<?php
declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;
use App\Models\User;

/**
 * 会话与鉴权。
 *
 * 相比上一版补上的：
 *  - 登录成功后 session_regenerate_id（防会话固定）
 *  - 登出时清 session 数据 + 过期 cookie
 *  - requireAuth / requireGuest / requireOwner 三个守卫
 *  - 记录 login_at，便于前端显示"上次登录"
 */
final class Auth
{
    private const USER_ID_KEY = 'user_id';
    private const LOGIN_AT_KEY = 'login_at';

    /** 同一次请求内缓存当前用户，避免 check()/user()/publicUser() 反复查库。 */
    private static ?array $cachedUser = null;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION[self::USER_ID_KEY]) ? (int) $_SESSION[self::USER_ID_KEY] : null;
    }

    /** 当前登录用户（含 password 字段，仅内部用）。被禁用的账号会被自动登出。 */
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

    /** 当前登录用户的对外表示（不含 password）。 */
    public static function publicUser(): ?array
    {
        $u = self::user();
        return $u === null ? null : User::publicView($u, true);
    }

    public static function login(array $user): void
    {
        // 防会话固定攻击：换一个 session id
        session_regenerate_id(true);
        $_SESSION[self::USER_ID_KEY] = (int) $user['id'];
        $_SESSION[self::LOGIN_AT_KEY] = date('c');
        self::$cachedUser = $user;
    }

    /** 资料/密码/头像更新后清掉请求内缓存，下一次读取拿到新数据。 */
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

    // ---------- 守卫（作为路由中间件使用） ----------

    public static function requireAuth(): void
    {
        if (!self::check()) {
            throw ApiException::unauthorized();
        }
    }

    public static function requireGuest(): void
    {
        if (self::check()) {
            throw ApiException::conflict('已经登录，请先登出');
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
            throw ApiException::forbidden('需要管理员权限');
        }
    }

    /** 资源归属校验：$ownerId 必须是当前用户。 */
    public static function requireOwner(int $ownerId, string $message = '只能操作自己的资源'): void
    {
        self::requireAuth();
        if (self::id() !== $ownerId) {
            throw ApiException::forbidden($message);
        }
    }
}
