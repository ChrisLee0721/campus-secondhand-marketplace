<?php
declare(strict_types=1);

namespace App\Models;

use App\Http\ApiException;
use PDO;

/**
 * 评价。表：reviews(id, order_id, seller_id, buyer_id, rating, comment, reply_text, replied_at, is_hidden, created_at)
 *
 * 评价挂在 order 上而不是 product 上 —— 评价属于「一次交易」，不是「一件商品」。
 * 一个订单只能评一次；只有 completed 订单可以评价；卖家可以回复一次。
 */
final class Review extends Model
{
    protected static string $table = 'reviews';

    protected static array $fillable = ['order_id', 'seller_id', 'buyer_id', 'rating', 'comment'];

    public const MIN_RATING = 1;
    public const MAX_RATING = 5;

    public static function findByOrder(int $orderId): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM reviews WHERE order_id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        return $stmt->fetch() ?: null;
    }

    /** 单条评价（带买家/商品信息）。 */
    public static function findDetailed(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT r.*, u.username AS buyer_name, u.avatar_path AS buyer_avatar_path,
                    p.title AS product_title
               FROM reviews r
               JOIN users u    ON r.buyer_id = u.id
               JOIN orders o   ON r.order_id = o.id
               JOIN products p ON o.product_id = p.id
              WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** 某卖家收到的评价（分页，不含已隐藏）。 */
    public static function forSeller(int $sellerId, int $page = 1, int $perPage = 10): array
    {
        return self::paginateReviews(
            'r.seller_id = :seller AND r.is_hidden = 0',
            [':seller' => $sellerId],
            $page,
            $perPage
        );
    }

    /** 某商品相关的评价（通过订单反查，分页，不含已隐藏）。 */
    public static function forProduct(int $productId, int $page = 1, int $perPage = 10): array
    {
        return self::paginateReviews(
            'o.product_id = :product AND r.is_hidden = 0',
            [':product' => $productId],
            $page,
            $perPage
        );
    }

    /** 卖家评分汇总。没有评价时返回 null 平均分，而不是 0（避免误导）。 */
    public static function sellerRating(int $sellerId): array
    {
        $stmt = static::db()->prepare(
            'SELECT COUNT(*) AS count, AVG(rating) AS average
               FROM reviews
              WHERE seller_id = ? AND is_hidden = 0'
        );
        $stmt->execute([$sellerId]);
        $row = $stmt->fetch();
        $count = (int) ($row['count'] ?? 0);

        return [
            'count'   => $count,
            'average' => $count > 0 ? round((float) $row['average'], 2) : null,
        ];
    }

    /** 管理员内容管理：所有评价（含隐藏）。 */
    public static function adminPaginate(int $page, int $perPage): array
    {
        $total = (int) static::db()->query('SELECT COUNT(*) FROM reviews')->fetchColumn();
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT r.*, b.username AS buyer_name, s.username AS seller_name,
                       p.title AS product_title
                  FROM reviews r
                  JOIN users b    ON r.buyer_id = b.id
                  JOIN users s    ON r.seller_id = s.id
                  JOIN orders o   ON r.order_id = o.id
                  JOIN products p ON o.product_id = p.id
              ORDER BY r.created_at DESC, r.id DESC
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

    /**
     * 创建评价。只有买家能评自己买过的订单，且一单一评。
     */
    public static function createForOrder(int $orderId, int $userId, int $rating, string $comment): int
    {
        $order = Order::find($orderId);
        if ($order === null) {
            throw ApiException::notFound('订单不存在');
        }
        if ((int) $order['buyer_id'] !== $userId) {
            throw ApiException::forbidden('只能评价自己购买的订单');
        }
        if ($order['status'] === Order::STATUS_CANCELLED) {
            throw ApiException::badRequest('已取消的订单不能评价');
        }
        if ($order['status'] !== Order::STATUS_COMPLETED) {
            throw ApiException::conflict('订单完成后才能评价');
        }
        if (self::findByOrder($orderId) !== null) {
            throw ApiException::conflict('该订单已经评价过了');
        }

        // seller_id 由后端从商品反查，不信任客户端传的值
        $product = Product::find((int) $order['product_id']);
        if ($product === null) {
            throw ApiException::notFound('订单关联的商品不存在');
        }

        return static::create([
            'order_id'  => $orderId,
            'seller_id' => (int) $product['seller_id'],
            'buyer_id'  => $userId,
            'rating'    => $rating,
            'comment'   => $comment,
        ]);
    }

    /** 卖家回复评价（只允许评价对应的卖家，回复可覆盖）。 */
    public static function reply(int $reviewId, int $sellerId, string $text): void
    {
        $review = self::find($reviewId);
        if ($review === null) {
            throw ApiException::notFound('评价不存在');
        }
        if ((int) $review['seller_id'] !== $sellerId) {
            throw ApiException::forbidden('只有卖家可以回复这条评价');
        }

        $stmt = static::db()->prepare(
            'UPDATE reviews SET reply_text = ?, replied_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$text, $reviewId]);
    }

    /** 管理员隐藏 / 恢复评价。 */
    public static function setHidden(int $id, bool $hidden): void
    {
        $stmt = static::db()->prepare('UPDATE reviews SET is_hidden = ? WHERE id = ?');
        $stmt->execute([(int) $hidden, $id]);
    }

    private static function paginateReviews(string $where, array $params, int $page, int $perPage): array
    {
        $countStmt = static::db()->prepare(
            "SELECT COUNT(*)
               FROM reviews r
               JOIN orders o   ON r.order_id = o.id
               JOIN products p ON o.product_id = p.id
              WHERE {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $sql = "SELECT r.*, u.username AS buyer_name, u.avatar_path AS buyer_avatar_path,
                       p.title AS product_title
                  FROM reviews r
                  JOIN users u    ON r.buyer_id = u.id
                  JOIN orders o   ON r.order_id = o.id
                  JOIN products p ON o.product_id = p.id
                 WHERE {$where}
              ORDER BY r.created_at DESC, r.id DESC
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

    public static function view(array $r): array
    {
        return [
            'id'                => (int) $r['id'],
            'order_id'          => (int) $r['order_id'],
            'seller_id'         => (int) $r['seller_id'],
            'seller_name'       => $r['seller_name'] ?? null,
            'buyer_id'          => (int) $r['buyer_id'],
            'buyer_name'        => $r['buyer_name'] ?? null,
            'buyer_avatar_path' => $r['buyer_avatar_path'] ?? null,
            'product_title'     => $r['product_title'] ?? null,
            'rating'            => (int) $r['rating'],
            'comment'           => $r['comment'],
            'reply_text'        => $r['reply_text'] ?? null,
            'replied_at'        => $r['replied_at'] ?? null,
            'is_hidden'         => (bool) ($r['is_hidden'] ?? false),
            'created_at'        => $r['created_at'],
        ];
    }
}
