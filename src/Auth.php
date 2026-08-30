<?php
declare(strict_types=1);

namespace Voletmat;

use PDO;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(app_config('session_name'));
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }

    public static function check(): bool
    {
        self::startSession();
        return !empty($_SESSION['user_id']);
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT id, login, nom FROM utilisateurs WHERE id = ?');
        $st->execute([(int) $_SESSION['user_id']]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function attempt(string $login, string $password): bool
    {
        $st = Database::pdo()->prepare('SELECT id, password_hash FROM utilisateurs WHERE login = ?');
        $st->execute([$login]);
        $row = $st->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        return true;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('login');
        }
    }
}
