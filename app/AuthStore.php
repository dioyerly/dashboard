<?php
declare(strict_types=1);
namespace Centro;

interface AuthStore
{
    public function admin(): ?array;
    public function create(string $email, string $hash): void;
    public function attempt(array $buckets, int $now, int $window): int;
    public function clear(array $keys): void;
}

// Real temporary authentication persistence, not a simulation of MySQL.
final class FileAuthStore implements AuthStore
{
    public function __construct(private readonly string $directory) {}

    private function locked(callable $operation): mixed
    {
        $lock = fopen($this->directory . '/auth.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Private authentication storage unavailable');
        try {
            $path = $this->directory . '/auth.json';
            $state = is_file($path) ? json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : ['admin' => null, 'limits' => []];
            if (!is_array($state) || !array_key_exists('admin', $state) || !isset($state['limits'])) throw new \RuntimeException('Invalid private authentication storage');
            [$result, $write] = $operation($state);
            if ($write) {
                $temporary = tempnam($this->directory, 'auth-');
                if ($temporary === false) throw new \RuntimeException('Private storage unavailable');
                try {
                    chmod($temporary, 0600);
                    $json = json_encode($state, JSON_THROW_ON_ERROR);
                    if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) throw new \RuntimeException('Private storage write failed');
                } finally { if (is_file($temporary)) unlink($temporary); }
            }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function admin(): ?array
    {
        return $this->locked(static fn(array &$state): array => [$state['admin'], false]);
    }

    public function create(string $email, string $hash): void
    {
        $this->locked(static function (array &$state) use ($email, $hash): array {
            if ($state['admin'] !== null) throw new \RuntimeException('An administrator already exists');
            $state['admin'] = ['id' => 1, 'email' => $email, 'password_hash' => $hash, 'session_version' => 1, 'active' => 1];
            return [null, true];
        });
    }

    public function attempt(array $buckets, int $now, int $window): int
    {
        return $this->locked(static function (array &$state) use ($buckets, $now, $window): array {
            $state['limits'] = array_filter($state['limits'], static fn(array $item): bool => $item['start'] + $window > $now);
            $retry = 0;
            foreach ($buckets as $key => $limit) {
                $entry = $state['limits'][$key] ?? ['start' => $now, 'count' => 0];
                if ($entry['count'] >= $limit) $retry = max($retry, $entry['start'] + $window - $now);
            }
            if ($retry > 0) return [$retry, true];
            foreach ($buckets as $key => $limit) {
                $state['limits'][$key] ??= ['start' => $now, 'count' => 0];
                $state['limits'][$key]['count']++;
            }
            return [0, true];
        });
    }

    public function clear(array $keys): void
    {
        $this->locked(static function (array &$state) use ($keys): array {
            foreach ($keys as $key) unset($state['limits'][$key]);
            return [null, true];
        });
    }
}

// Prepared for an explicitly authorized MySQL phase; not executed by default.
final class MysqlAuthStore implements AuthStore
{
    public function __construct(private readonly \PDO $pdo) {}

    public function admin(): ?array
    {
        $query = $this->pdo->prepare('SELECT id, email, password_hash, session_version, active FROM auth_users WHERE id = ?');
        $query->execute([1]);
        return $query->fetch() ?: null;
    }

    public function create(string $email, string $hash): void
    {
        $query = $this->pdo->prepare('INSERT INTO auth_users (id, email, password_hash, session_version, active) VALUES (?, ?, ?, ?, ?)');
        $query->execute([1, $email, $hash, 1, 1]);
    }

    public function attempt(array $buckets, int $now, int $window): int
    {
        $this->pdo->beginTransaction();
        try {
            ksort($buckets); // Consistent lock order.
            $entries = [];
            $retry = 0;
            foreach ($buckets as $key => $limit) {
                $insert = $this->pdo->prepare('INSERT INTO auth_login_limits (bucket_key, window_start, attempts) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE bucket_key = bucket_key');
                $insert->execute([$key, $now, 0]);
                $select = $this->pdo->prepare('SELECT window_start, attempts FROM auth_login_limits WHERE bucket_key = ? FOR UPDATE');
                $select->execute([$key]);
                $entry = $select->fetch();
                $entries[$key] = (int)$entry['window_start'] + $window <= $now ? ['window_start' => $now, 'attempts' => 0] : $entry;
                if ((int)$entries[$key]['attempts'] >= $limit) $retry = max($retry, (int)$entries[$key]['window_start'] + $window - $now);
            }
            if ($retry === 0) {
                foreach ($entries as $key => $entry) {
                    $update = $this->pdo->prepare('UPDATE auth_login_limits SET window_start = ?, attempts = ? WHERE bucket_key = ?');
                    $update->execute([$entry['window_start'], (int)$entry['attempts'] + 1, $key]);
                }
            }
            if ($retry > 0) { $this->pdo->rollBack(); return $retry; }
            $this->pdo->commit();
            return $retry;
        } catch (\Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    public function clear(array $keys): void
    {
        $delete = $this->pdo->prepare('DELETE FROM auth_login_limits WHERE bucket_key = ?');
        foreach ($keys as $key) $delete->execute([$key]);
    }
}
