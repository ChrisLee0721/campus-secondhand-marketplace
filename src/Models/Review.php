<?php
declare(strict_types=1);

namespace App\Models;

use App\Http\ApiException;
use PDO;

/**
 * Reviews. Table: reviews(id, order_id, seller_id, buyer_id, rating, comment, reply_text, replied_at, is_hidden, created_at)
 *
 * A review belongs to an order, not to a product: it rates one transaction, not one item.
 * Each order can be reviewed once, only completed orders qualify, and the seller may reply once.
 */
final class Review extends Model
{
    protected static string $table = 'reviews';

    protected static array $fillable = ['order_id', 'direction', 'seller_id', 'buyer_id', 'rating', 'comment'];

    public const MIN_RATING = 1;
    public const MAX_RATING = 5;
    public const DIRECTION_BUYER_TO_SELLER = 'buyer_to_seller';
    public const DIRECTION_SELLER_TO_BUYER = 'seller_to_buyer';

    public static function findByOrder(int $orderId, string $direction = self::DIRECTION_BUYER_TO_SELLER): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM reviews WHERE order_id = ? AND direction = ? LIMIT 1');
        $stmt->execute([$orderId, $direction]);
        return $stmt->fetch() ?: null;
    }

    /** One review with buyer and listing details. */
    public static function findDetailed(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT r.*, u.username AS buyer_name, u.avatar_path AS buyer_avatar_path,
                    s.username AS seller_name, p.title AS product_title
               FROM reviews r
               JOIN users u    ON r.buyer_id = u.id
               JOIN users s    ON r.seller_id = s.id
               JOIN orders o   ON r.order_id = o.id
               JOIN products p ON o.product_id = p.id
              WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Reviews received by a seller (paginated, hidden ones excluded). */
    public static function forSeller(int $sellerId, int $page = 1, int $perPage = 10): array
    {
        return self::paginateReviews(
            'r.seller_id = :seller AND r.direction = \'buyer_to_seller\' AND r.is_hidden = 0',
            [':seller' => $sellerId],
            $page,
            $perPage
        );
    }

    /** Reviews tied to a listing through its orders (paginated, hidden ones excluded). */
    public static function forProduct(int $productId, int $page = 1, int $perPage = 10): array
    {
        return self::paginateReviews(
            'o.product_id = :product AND r.direction = \'buyer_to_seller\' AND r.is_hidden = 0',
            [':product' => $productId],
            $page,
            $perPage
        );
    }

    /** Reviews received by a buyer from sellers (paginated, hidden ones excluded). */
    public static function forBuyer(int $buyerId, int $page = 1, int $perPage = 10): array
    {
        return self::paginateReviews(
            'r.buyer_id = :buyer AND r.direction = \'seller_to_buyer\' AND r.is_hidden = 0',
            [':buyer' => $buyerId],
            $page,
            $perPage
        );
    }

    /** Seller rating summary (buyer reviews of the seller only). */
    public static function sellerRating(int $sellerId): array
    {
        return self::ratingFor('seller_id', $sellerId, self::DIRECTION_BUYER_TO_SELLER);
    }

    /** Buyer rating summary (seller reviews of the buyer only). */
    public static function buyerRating(int $buyerId): array
    {
        return self::ratingFor('buyer_id', $buyerId, self::DIRECTION_SELLER_TO_BUYER);
    }

    private static function ratingFor(string $column, int $userId, string $direction): array
    {
        $stmt = static::db()->prepare(
            "SELECT COUNT(*) AS count, AVG(rating) AS average
               FROM reviews
              WHERE {$column} = ? AND direction = ? AND is_hidden = 0"
        );
        $stmt->execute([$userId, $direction]);
        $row = $stmt->fetch();
        $count = (int) ($row['count'] ?? 0);

        return [
            'count'   => $count,
            'average' => $count > 0 ? round((float) $row['average'], 2) : null,
        ];
    }

    /** Admin content management: every review, hidden ones included. */
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
     * Create a review. Buyer and seller may each review once; the direction follows the current user.
     */
    public static function createForOrder(int $orderId, int $userId, int $rating, string $comment): int
    {
        $order = Order::find($orderId);
        if ($order === null) {
            throw ApiException::notFound('Order not found');
        }

        $product = Product::find((int) $order['product_id']);
        if ($product === null) {
            throw ApiException::notFound('The product for this order no longer exists');
        }

        $isBuyer = (int) $order['buyer_id'] === $userId;
        $isSeller = (int) $product['seller_id'] === $userId;
        if (!$isBuyer && !$isSeller) {
            throw ApiException::forbidden('You can only review orders you participated in');
        }
        if ($order['status'] === Order::STATUS_CANCELLED) {
            throw ApiException::badRequest('Cancelled orders cannot be reviewed');
        }
        if ($order['status'] !== Order::STATUS_COMPLETED) {
            throw ApiException::conflict('You can review only after the order is completed');
        }

        $direction = $isBuyer ? self::DIRECTION_BUYER_TO_SELLER : self::DIRECTION_SELLER_TO_BUYER;
        if (self::findByOrder($orderId, $direction) !== null) {
            throw ApiException::conflict('This order has already been reviewed');
        }

        return static::create([
            'order_id'  => $orderId,
            'direction' => $direction,
            'seller_id' => (int) $product['seller_id'],
            'buyer_id'  => (int) $order['buyer_id'],
            'rating'    => $rating,
            'comment'   => $comment,
        ]);
    }

    /** Seller reply to a review (only that review's seller, and the reply can be replaced). */
    public static function reply(int $reviewId, int $sellerId, string $text): void
    {
        $review = self::find($reviewId);
        if ($review === null) {
            throw ApiException::notFound('Review not found');
        }
        if ((int) $review['seller_id'] !== $sellerId
            || $review['direction'] !== self::DIRECTION_BUYER_TO_SELLER) {
            throw ApiException::forbidden('Only the seller can reply to this review');
        }

        $stmt = static::db()->prepare(
            'UPDATE reviews SET reply_text = ?, replied_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$text, $reviewId]);
    }

    /** Admin hides or restores a review. */
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
                       s.username AS seller_name, p.title AS product_title
                  FROM reviews r
                  JOIN users u    ON r.buyer_id = u.id
                  JOIN users s    ON r.seller_id = s.id
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
            'seller_name'       => $r['seller_name'] ?? null,
            'direction'         => $r['direction'] ?? self::DIRECTION_BUYER_TO_SELLER,
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
