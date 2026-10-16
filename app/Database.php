<?php
declare(strict_types=1);
namespace Centro;

final class Database
{
    public static function connection(array $config): \PDO
    {
        // Explicit opt-in required. No connection is made by loading this class.
        if (!$config['enabled']) throw new \RuntimeException('Database connections are disabled');
        if (!in_array('mysql', \PDO::getAvailableDrivers(), true)) throw new \RuntimeException('PDO MySQL unavailable');
        if (!preg_match('/\A[a-zA-Z0-9._-]+\z/', $config['host']) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $config['name'])) {
            throw new \RuntimeException('Invalid database configuration');
        }
        if ($config['password'] === '') throw new \RuntimeException('Database credentials not configured');
        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_TIMEOUT => 5];
        if ($config['tls_ca'] !== '') {
            $options[\PDO::MYSQL_ATTR_SSL_CA] = $config['tls_ca'];
            $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        try {
            return new \PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['name'] . ';charset=utf8mb4',
                $config['user'], $config['password'], $options);
        } catch (\Throwable $error) {
            // Do not propagate DSN, database user, passwords or driver messages to HTTP.
            throw new \RuntimeException('Database unavailable');
        }
    }
}
