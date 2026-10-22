<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Small helper for the admin session + CSRF token.
 */
final class AdminSession
{
    /** 30 days — persistent, web-app-style login. */
    private const LIFETIME = 2592000;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Private session store, ABOVE the web root, so the shared /tmp GC from
        // other apps on the box can't wipe our sessions out from under us.
        $dir = dirname(__DIR__, 2) . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        @ini_set('session.gc_maxlifetime', (string) self::LIFETIME);

        session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Lax',
        ]);
        // NOTE: cookie name changed from the old 'gsb_admin_sess' so any stale
        // cookie (e.g. one scoped to /admin) is ignored — that was causing the
        // "logged out when navigating" jank.
        session_name('gsb_session');
        session_start();
    }

    public static function isAuthed(): bool
    {
        return !empty($_SESSION['gsb_uid']);
    }

    /** @param array<string,mixed> $account */
    public static function login(array $account): void
    {
        // Prevent session fixation.
        session_regenerate_id(true);
        $_SESSION['gsb_uid']   = (int) $account['id'];
        $_SESSION['gsb_role']  = (string) $account['role'];
        $_SESSION['gsb_team']  = $account['team_id'] !== null ? (string) $account['team_id'] : null;
        $_SESSION['gsb_email'] = (string) $account['email'];
    }

    public static function role(): string
    {
        return (string) ($_SESSION['gsb_role'] ?? '');
    }

    public static function isSuper(): bool
    {
        return self::role() === 'superadmin';
    }

    public static function teamId(): ?string
    {
        $t = $_SESSION['gsb_team'] ?? null;
        return $t !== null && $t !== '' ? (string) $t : null;
    }

    public static function email(): string
    {
        return (string) ($_SESSION['gsb_email'] ?? '');
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return !empty($_SESSION['csrf'])
            && is_string($token)
            && hash_equals((string) $_SESSION['csrf'], $token);
    }
}
