<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * 用户模型。表：users(id, username, password, email, created_at, is_admin)
 * 004 迁移后另有 avatar_path / is_active。
 */
final class User extends Model
{
    protected static string $table = 'users';

    protected static array $fillable = ['username', 'password', 'email', 'avatar_path', 'bio'];

    public static function findByEmail(string $email): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    public static function emailTaken(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE email = ?';
        $args = [$email];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $exceptId;
        }
        $stmt = static::db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($args);
        return (bool) $stmt->fetchColumn();
    }

    public static function usernameTaken(string $username, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE username = ?';
        $args = [$username];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $exceptId;
        }
        $stmt = static::db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($args);
        return (bool) $stmt->fetchColumn();
    }


    /** 管理员用户列表（带商品数），支持用户名 / 邮箱搜索。 */
    public static function adminPaginate(string $search, int $page, int $perPage): array
    {
        $where = '';
        $params = [];

        if ($search !== '') {
            // 原生预处理不允许复用同一个命名占位符，所以拆成两个
            $where = 'WHERE (u.username LIKE :kw_user OR u.email LIKE :kw_email)';
            $keyword = '%' . str_replace(['%', '_', '\\'], ['\%', '\_', '\\\\'], $search) . '%';
            $params[':kw_user'] = $keyword;
            $params[':kw_email'] = $keyword;
        }

        $countStmt = static::db()->prepare("SELECT COUNT(*) FROM users u {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $sql = "SELECT u.id, u.username, u.email, u.avatar_path, u.created_at,
                       u.is_admin, u.is_active,
                       (SELECT COUNT(*) FROM products p WHERE p.seller_id = u.id) AS product_count
                  FROM users u
                  {$where}
                 ORDER BY u.id ASC
                 LIMIT :limit OFFSET :offset";

        $stmt = static::db()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items'       => $stmt->fetchAll(),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /** 管理员视角读取单个用户（含商品数）。 */
    public static function adminFind(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT u.id, u.username, u.email, u.avatar_path, u.created_at,
                    u.is_admin, u.is_active,
                    (SELECT COUNT(*) FROM products p WHERE p.seller_id = u.id) AS product_count
               FROM users u
              WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** 管理员视角的用户表示（含邮箱、状态、商品数）。 */
    public static function adminView(array $user): array
    {
        return [
            'id'            => (int) $user['id'],
            'username'      => $user['username'],
            'email'         => $user['email'],
            'avatar_path'   => $user['avatar_path'] ?? null,
            'created_at'    => $user['created_at'],
            'is_admin'      => (bool) ($user['is_admin'] ?? false),
            'is_active'     => (bool) ($user['is_active'] ?? true),
            'product_count' => (int) ($user['product_count'] ?? 0),
        ];
    }

    /** 管理员专用：直接改角色/启用状态，不走 $fillable，避免批量赋值漏洞。 */
    public static function setFlags(int $id, bool $isAdmin, bool $isActive): void
    {
        $stmt = static::db()->prepare('UPDATE users SET is_admin = ?, is_active = ? WHERE id = ?');
        $stmt->execute([(int) $isAdmin, (int) $isActive, $id]);
    }

    /** 当前启用状态的管理员数量（防止把最后一个管理员降级/禁用）。 */
    public static function activeAdminCount(): int
    {
        return (int) static::db()
            ->query('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1')
            ->fetchColumn();
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /** 对外输出用的用户表示 —— 永远不含 password 字段。 */
    public static function publicView(array $user, bool $includeEmail = false): array
    {
        $view = [
            'id'          => (int) $user['id'],
            'username'    => $user['username'],
            'avatar_path' => $user['avatar_path'] ?? null,
            'bio'         => $user['bio'] ?? null,
            'created_at'  => $user['created_at'],
        ];
        if ($includeEmail) {
            if (isset($user['email'])) {
                $view['email'] = $user['email'];
            }
            $view['is_admin']  = (bool) ($user['is_admin'] ?? false);
            $view['is_active'] = (bool) ($user['is_active'] ?? true);
        }
        return $view;
    }
}
