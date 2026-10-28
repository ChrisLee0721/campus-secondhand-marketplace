<?php
declare(strict_types=1);

namespace App\Models;

use App\Http\ApiException;
use PDO;

/**
 * 个人主页留言板。表：profile_comments
 */
final class ProfileComment extends Model
{
    protected static string $table = 'profile_comments';

    protected static array $fillable = ['profile_user_id', 'author_id', 'body'];

    public static function forUser(int $profileUserId, int $page = 1, int $perPage = 10): array
    {
        $countStmt = static::db()->prepare(
            'SELECT COUNT(*) FROM profile_comments WHERE profile_user_id = ? AND is_hidden = 0'
        );
        $countStmt->execute([$profileUserId]);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT c.*, u.username AS author_name, u.avatar_path AS author_avatar_path
                  FROM profile_comments c
                  JOIN users u ON c.author_id = u.id
                 WHERE c.profile_user_id = :profile AND c.is_hidden = 0
              ORDER BY c.created_at DESC, c.id DESC
                 LIMIT :limit OFFSET :offset';
        $stmt = static::db()->prepare($sql);
        $stmt->bindValue(':profile', $profileUserId, PDO::PARAM_INT);
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

    /** 单条留言（带作者/主页主人信息，管理员用）。 */
    public static function findDetailed(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT c.*, u.username AS author_name, u.avatar_path AS author_avatar_path,
                    p.username AS profile_name
               FROM profile_comments c
               JOIN users u ON c.author_id = u.id
               JOIN users p ON c.profile_user_id = p.id
              WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function adminPaginate(int $page, int $perPage): array
    {
        $total = (int) static::db()->query('SELECT COUNT(*) FROM profile_comments')->fetchColumn();
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT c.*, u.username AS author_name, u.avatar_path AS author_avatar_path,
                       p.username AS profile_name
                  FROM profile_comments c
                  JOIN users u ON c.author_id = u.id
                  JOIN users p ON c.profile_user_id = p.id
              ORDER BY c.created_at DESC, c.id DESC
                 LIMIT :limit OFFSET :offset';
        $stmt = static::db()->prepare($sql);
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

    public static function add(int $profileUserId, int $authorId, string $body): int
    {
        if (!User::exists($profileUserId)) {
            throw ApiException::notFound('用户不存在');
        }

        return static::create([
            'profile_user_id' => $profileUserId,
            'author_id'       => $authorId,
            'body'            => $body,
        ]);
    }

    /** 作者、被留言的主页主人、管理员可以隐藏留言。 */
    public static function hide(int $id, int $actorId, bool $isAdmin): void
    {
        $comment = self::find($id);
        if ($comment === null) {
            throw ApiException::notFound('留言不存在');
        }

        $isAuthor = (int) $comment['author_id'] === $actorId;
        $isOwner = (int) $comment['profile_user_id'] === $actorId;
        if (!$isAdmin && !$isAuthor && !$isOwner) {
            throw ApiException::forbidden('没有权限删除这条留言');
        }

        static::setHidden($id, true);
    }

    public static function setHidden(int $id, bool $hidden): void
    {
        $stmt = static::db()->prepare('UPDATE profile_comments SET is_hidden = ? WHERE id = ?');
        $stmt->execute([(int) $hidden, $id]);
    }

    public static function view(array $c): array
    {
        return [
            'id'               => (int) $c['id'],
            'profile_user_id'  => (int) $c['profile_user_id'],
            'author_id'        => (int) $c['author_id'],
            'author_name'      => $c['author_name'] ?? null,
            'author_avatar_path' => $c['author_avatar_path'] ?? null,
            'profile_name'     => $c['profile_name'] ?? null,
            'body'             => $c['body'],
            'is_hidden'        => (bool) ($c['is_hidden'] ?? false),
            'created_at'       => $c['created_at'],
        ];
    }
}
