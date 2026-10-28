<?php
declare(strict_types=1);

namespace App\Models;

use App\Http\ApiException;
use PDO;

/**
 * 商品留言 / 售前问答。表：product_comments
 * 一级留言 parent_id = NULL；卖家回复挂在一级留言下（只支持一层）。
 */
final class ProductComment extends Model
{
    protected static string $table = 'product_comments';

    protected static array $fillable = ['product_id', 'user_id', 'parent_id', 'body'];

    /** 某商品的留言（分页，一级留言 + 其回复）。 */
    public static function forProduct(int $productId, int $page = 1, int $perPage = 10): array
    {
        $countStmt = static::db()->prepare(
            'SELECT COUNT(*) FROM product_comments
              WHERE product_id = ? AND parent_id IS NULL AND is_hidden = 0'
        );
        $countStmt->execute([$productId]);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT c.*, u.username AS user_name, u.avatar_path AS user_avatar_path
                  FROM product_comments c
                  JOIN users u ON c.user_id = u.id
                 WHERE c.product_id = :product AND c.parent_id IS NULL AND c.is_hidden = 0
              ORDER BY c.created_at DESC, c.id DESC
                 LIMIT :limit OFFSET :offset';
        $stmt = static::db()->prepare($sql);
        $stmt->bindValue(':product', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $repliesByParent = [];

        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $replyStmt = static::db()->prepare(
                "SELECT c.*, u.username AS user_name, u.avatar_path AS user_avatar_path
                   FROM product_comments c
                   JOIN users u ON c.user_id = u.id
                  WHERE c.parent_id IN ({$placeholders}) AND c.is_hidden = 0
               ORDER BY c.created_at ASC, c.id ASC"
            );
            $replyStmt->execute($ids);
            foreach ($replyStmt->fetchAll() as $reply) {
                $repliesByParent[(int) $reply['parent_id']][] = self::view($reply);
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $view = self::view($row);
            $view['replies'] = $repliesByParent[(int) $row['id']] ?? [];
            $items[] = $view;
        }

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /** 单条留言（带用户/商品信息，管理员用）。 */
    public static function findDetailed(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT c.*, u.username AS user_name, u.avatar_path AS user_avatar_path,
                    p.title AS product_title
               FROM product_comments c
               JOIN users u    ON c.user_id = u.id
               JOIN products p ON c.product_id = p.id
              WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** 管理员内容管理：所有商品留言（含隐藏）。 */
    public static function adminPaginate(int $page, int $perPage): array
    {
        $total = (int) static::db()->query('SELECT COUNT(*) FROM product_comments')->fetchColumn();
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT c.*, u.username AS user_name, u.avatar_path AS user_avatar_path,
                       p.title AS product_title
                  FROM product_comments c
                  JOIN users u    ON c.user_id = u.id
                  JOIN products p ON c.product_id = p.id
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

    public static function add(int $productId, int $userId, string $body, ?int $parentId = null): int
    {
        $product = Product::find($productId);
        if ($product === null) {
            throw ApiException::notFound('商品不存在');
        }

        if ($parentId !== null) {
            $parent = self::find($parentId);
            if ($parent === null || (int) $parent['product_id'] !== $productId) {
                throw ApiException::badRequest('回复的留言不存在');
            }
            if ($parent['parent_id'] !== null) {
                throw ApiException::badRequest('只支持回复一级留言');
            }
        }

        return static::create([
            'product_id' => $productId,
            'user_id'    => $userId,
            'parent_id'  => $parentId,
            'body'       => $body,
        ]);
    }

    /** 作者、商品卖家、管理员可以隐藏留言。 */
    public static function hide(int $id, int $actorId, bool $isAdmin): void
    {
        $comment = self::find($id);
        if ($comment === null) {
            throw ApiException::notFound('留言不存在');
        }

        $product = Product::find((int) $comment['product_id']);
        $isAuthor = (int) $comment['user_id'] === $actorId;
        $isSeller = $product !== null && (int) $product['seller_id'] === $actorId;

        if (!$isAdmin && !$isAuthor && !$isSeller) {
            throw ApiException::forbidden('没有权限删除这条留言');
        }

        static::setHidden($id, true);
    }

    public static function setHidden(int $id, bool $hidden): void
    {
        $stmt = static::db()->prepare('UPDATE product_comments SET is_hidden = ? WHERE id = ?');
        $stmt->execute([(int) $hidden, $id]);
    }

    public static function view(array $c): array
    {
        return [
            'id'          => (int) $c['id'],
            'product_id'  => (int) $c['product_id'],
            'user_id'     => (int) $c['user_id'],
            'parent_id'   => $c['parent_id'] !== null ? (int) $c['parent_id'] : null,
            'body'        => $c['body'],
            'user_name'   => $c['user_name'] ?? null,
            'user_avatar_path' => $c['user_avatar_path'] ?? null,
            'product_title'    => $c['product_title'] ?? null,
            'is_hidden'   => (bool) ($c['is_hidden'] ?? false),
            'created_at'  => $c['created_at'],
        ];
    }
}
