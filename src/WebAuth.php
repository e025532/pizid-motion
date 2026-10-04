<?php

declare(strict_types=1);

final class WebAuth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('health_dashboard');
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    public static function status(): never
    {
        self::start();
        jsonResponse([
            'authenticated' => !self::enabled() || ($_SESSION['dashboard_authenticated'] ?? false) === true,
            'passwordless' => !self::enabled(),
        ]);
    }

    public static function login(): never
    {
        self::start();
        [$body] = requestBody();
        $password = optionalString($body, 'password', 256) ?? '';
        $encodedHash = config()['web_password_hash_b64'] ?? '';
        $hash = $encodedHash === '' ? false : base64_decode($encodedHash, true);
        if (!is_string($hash) || $hash === '') {
            throw new ApiException(503, 'Dashboard access is not configured');
        }
        if (!password_verify($password, $hash)) {
            usleep(350000);
            throw new ApiException(401, 'Mot de passe incorrect');
        }
        session_regenerate_id(true);
        $_SESSION['dashboard_authenticated'] = true;
        $_SESSION['dashboard_login_at'] = time();
        jsonResponse(['authenticated' => true]);
    }

    public static function logout(): never
    {
        self::start();
        $_SESSION = [];
        session_destroy();
        jsonResponse(['authenticated' => false]);
    }

    public static function requireLogin(): void
    {
        if (!self::enabled()) {
            return;
        }
        self::start();
        if (($_SESSION['dashboard_authenticated'] ?? false) !== true) {
            throw new ApiException(401, 'Authentication required');
        }
    }

    public static function enabled(): bool
    {
        return (config()['web_password_hash_b64'] ?? '') !== '';
    }
}
