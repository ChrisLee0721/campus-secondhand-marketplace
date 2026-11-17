<?php
declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;
use App\Models\User;

/**
 * Sessions and authorisation.
 *
 * Compared with the previous version this adds:
 *  - session_regenerate_id after login (prevents session fixation)
 *  - clearing session data and expiring the cookie on logout
 *  - the requireAuth / requireGuest / requireOwner guards
 *  - login_at, so the front end can show the last login time
 */
final class Auth
{
    private const USER_ID_KEY = 'user_id';
    private const LOGIN_AT_KEY = 'login_at';

    /** Cache the current user for this request so check()/user()/publicUser() do not query again. */
    private static ?array $cachedUser = null;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION[self::USER_ID_KEY]) ? (int) $_SESSION[self::USER_ID_KEY] : null;
    }

    /** The signed-in user including the password column, internal use only. Disabled accounts are logged out. */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            if ((int) (self::$cachedUser['is_active'] ?? 1) === 0) {
                self::logout();
                return null;
            }
            return self::$cachedUser;
        }

        $id = self::id();
        if ($id === null) {
            return null;
        }

        $user = User::find($id);
        if ($user === null || (int) ($user['is_active'] ?? 1) === 0) {
            self::logout();
            return null;
        }

        return self::$cachedUser = $user;
    }

    /** The public shape of the signed-in user, without the password column. */
    public static function publicUser(): ?array
    {
        $u = self::user();
        return $u === null ? null : User::publicView($u, true);
    }

    public static function login(array $user): void
    {
        // Prevent session fixation: switch to a new session id
        session_regenerate_id(true);
        $_SESSION[self::USER_ID_KEY] = (int) $user['id'];
        $_SESSION[self::LOGIN_AT_KEY] = date('c');
        \App\Database::conn()
            ->prepare('UPDATE users SET last_active_at = NOW() WHERE id = ?')
            ->execute([(int) $user['id']]);
        self::$cachedUser = $user;
    }

    /** Drop the per-request cache after a profile, password or avatar update. */
    public static function refresh(): void
    {
        self::$cachedUser = null;
    }

    public static function logout(): void
    {
        self::$cachedUser = null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    // ---------- Guards (usable as route middleware) ----------

    public static function requireAuth(): void
    {
        if (!self::check()) {
            throw ApiException::unauthorized();
        }
    }

    public static function requireGuest(): void
    {
        if (self::check()) {
            throw ApiException::conflict('You are already logged in. Please log out first.');
        }
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && !empty($user['is_admin']);
    }

    public static function requireAdmin(): void
    {
        self::requireAuth();
        if (!self::isAdmin()) {
            throw ApiException::forbidden('Admin access required');
        }
    }

    /** Ownership check: $ownerId must be the current user. */
    public static function requireOwner(int $ownerId, string $message = 'You can only access your own resources'): void
    {
        self::requireAuth();
        if (self::id() !== $ownerId) {
            throw ApiException::forbidden($message);
        }
    }
}
