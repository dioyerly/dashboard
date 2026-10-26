<?php
declare(strict_types=1);
namespace Centro;

final class Security
{
    public static function secureTransport(): bool
    {
        // Do not trust client-supplied X-Forwarded-Proto on shared hosting.
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    }

    public static function path(): string
    {
        $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        return rtrim($directory, '/') . '/';
    }

    public static function headers(): void
    {
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob: https:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
        if (self::secureTransport()) header('Strict-Transport-Security: max-age=31536000');
    }

    public static function session(array $config): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string)$config['absolute_seconds']);
        session_save_path($config['private_dir'] . '/sessions');
        session_name('CENTROSESSID');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => self::secureTransport(), 'httponly' => true, 'samesite' => 'Strict']);
        if (!session_start()) throw new \RuntimeException('Session storage unavailable');
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrf(): string { return $_SESSION['csrf']; }

    public static function validCsrf(?string $token): bool
    {
        return is_string($token) && strlen($token) === 64 && hash_equals(self::csrf(), $token);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        session_destroy();
    }
}
