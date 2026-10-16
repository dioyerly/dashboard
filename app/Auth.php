<?php
declare(strict_types=1);
namespace Centro;

final class Auth
{
    // Valid bcrypt dummy hash avoids a fast unknown-email branch; never an account.
    private const DUMMY = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';

    public function __construct(private readonly AuthStore $store, private readonly array $config) {}

    public function configured(): bool { return $this->store->admin() !== null; }

    public function current(bool $touch = false): ?array
    {
        if (!isset($_SESSION['user_id'], $_SESSION['issued_at'], $_SESSION['last_seen'])) return null;
        $now = time();
        if ($now - $_SESSION['last_seen'] >= $this->config['idle_seconds'] || $now - $_SESSION['issued_at'] >= $this->config['absolute_seconds']) {
            $this->invalidate();
            return null;
        }
        $admin = $this->store->admin();
        if (!$admin || !(bool)$admin['active'] || (int)$admin['id'] !== (int)$_SESSION['user_id'] || (int)$admin['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
            $this->invalidate();
            return null;
        }
        if ($touch) $_SESSION['last_seen'] = $now;
        return ['id' => (int)$admin['id'], 'email' => $admin['email']];
    }

    private function invalidate(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public function login(string $email, string $password, string $ip): array
    {
        $ipKey = hash('sha256', 'ip:' . $ip);
        $accountKey = hash('sha256', 'account'); // One account; rotating emails cannot evade this bucket.
        $globalKey = hash('sha256', 'global');
        $retry = $this->store->attempt([$ipKey => $this->config['rate_limit'], $accountKey => $this->config['rate_limit'], $globalKey => $this->config['rate_global_limit']], time(), $this->config['rate_window']);
        if ($retry > 0) return ['ok' => false, 'status' => 429, 'code' => 'rate_limited', 'retry' => $retry];
        $admin = $this->store->admin();
        $verified = password_verify($password, $admin['password_hash'] ?? self::DUMMY);
        $email = strtolower(trim($email));
        if (!$admin || !$verified || !(bool)$admin['active'] || !hash_equals($admin['email'], $email)) {
            return ['ok' => false, 'status' => 401, 'code' => 'invalid_credentials'];
        }
        $this->store->clear([$ipKey, $accountKey]);
        session_regenerate_id(true);
        $_SESSION = ['user_id' => (int)$admin['id'], 'session_version' => (int)$admin['session_version'],
            'issued_at' => time(), 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
        return ['ok' => true];
    }
}
