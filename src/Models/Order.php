<?php
declare(strict_types=1);

namespace App\Models;

use App\Http\ApiException;
use PDO;

/**
 * 订单。表：orders(id, buyer_id, product_id, quantity, total_price, order_date, status)
 *
 * 注意这张表的设计是「一个商品一行」，不是「一个订单多行」——
 * 这是原 schema 决定的（没有 order_items 表），所以一次结算 N 个购物车项
 * 会产生 N 条 orders 记录。这是二手交易平台的合理模型（每件二手货只有一件）。
 */
final class Order extends Model
{
    protected static string $table = 'orders';

    protected static array $fillable = ['buyer_id', 'product_id', 'quantity', 'total_price', 'status'];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function forBuyer(int $buyerId): array
    {
        $sql = 'SELECT o.*, p.title, p.image_path, p.seller_id,
                       s.username AS seller_name,
                       r.id AS review_id, r.rating AS review_rating
                  FROM orders o
                  JOIN products p ON o.product_id = p.id
                  JOIN users    s ON p.seller_id = s.id
                  LEFT JOIN reviews r ON r.order_id = o.id
                 WHERE o.buyer_id = ?
              ORDER BY o.order_date DESC, o.id DESC';
        $stmt = static::db()->prepare($sql);
        $stmt->execute([$buyerId]);
        return $stmt->fetchAll();
    }

    public static function forSeller(int $sellerId): array
    {
        $sql = 'SELECT o.*, p.title, p.image_path,
                       b.username AS buyer_name
                  FROM orders o
                  JOIN products p ON o.product_id = p.id
                  JOIN users    b ON o.buyer_id = b.id
                 WHERE p.seller_id = ?
              ORDER BY o.order_date DESC, o.id DESC';
        $stmt = static::db()->prepare($sql);
        $stmt->execute([$sellerId]);
        return $stmt->fetchAll();
    }

    public static function findDetailed(int $id): ?array
    {
        $sql = 'SELECT o.*, p.title, p.image_path, p.seller_id,
                       b.username AS buyer_name, s.username AS seller_name
                  FROM orders o
                  JOIN products p ON o.product_id = p.id
                  JOIN users    b ON o.buyer_id  = b.id
                  JOIN users    s ON p.seller_id = s.id
                 WHERE o.id = ?';
        $stmt = static::db()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * 结算购物车里指定的条目（不传 ids 则全部结算）。
     *
     * 整个过程在一个事务里：
     *   1. 逐个商品做「条件更新」把 is_sold 从 0 改成 1（rowCount=0 说明被别人抢先买了）
     *   2. 写 orders
     *   3. 从 cart 删除已结算项
     * 任何一步失败全部回滚 —— 不会出现「扣了商品但没生成订单」。
     *
     * @param array<int,int> $cartIds
     * @return array{orders:array<int,array>, total:float}
     */
    public static function checkout(int $buyerId, array $cartIds = []): array
    {
        return \App\Database::transaction(static function (PDO $pdo) use ($buyerId, $cartIds) {
            $cart = CartItem::forUser($buyerId);

            if ($cart === []) {
                throw ApiException::badRequest('购物车是空的');
            }

            if ($cartIds !== []) {
                $wanted = array_map('intval', $cartIds);
                $cart = array_values(array_filter(
                    $cart,
                    static fn (array $row): bool => in_array((int) $row['id'], $wanted, true)
                ));
                if ($cart === []) {
                    throw ApiException::badRequest('指定的购物车条目不存在');
                }
            }

            // 已售出的条目直接跳过 —— 购物车合计本来就不含它们。
            // 如果用户选中的全是被买走的商品，再明确报错。
            $cart = array_values(array_filter(
                $cart,
                static fn (array $row): bool => (int) $row['is_sold'] === 0
            ));
            if ($cart === []) {
                throw ApiException::conflict('购物车中没有可结算的在售商品');
            }

            $created = [];
            $grandTotal = 0.0;

            foreach ($cart as $row) {
                $productId = (int) $row['product_id'];
                $price     = (float) $row['price'];

                // 条件更新：只有仍在售才能抢到。上面已经过滤过一遍，
                // 这里只处理"读完购物车到真正扣下之间被人抢先"的竞态。
                $claim = $pdo->prepare('UPDATE products SET is_sold = 1 WHERE id = ? AND is_sold = 0');
                $claim->execute([$productId]);
                if ($claim->rowCount() !== 1) {
                    throw ApiException::conflict("「{$row['title']}」已被他人买走，请刷新购物车后重试");
                }

                // 每件二手商品只有一份，所以订单行数量固定为 1。
                $lineTotal = round($price, 2);
                $grandTotal += $lineTotal;

                $insert = $pdo->prepare(
                    'INSERT INTO orders (buyer_id, product_id, quantity, total_price, status)
                     VALUES (?, ?, 1, ?, ?)'
                );
                $insert->execute([$buyerId, $productId, (string) $lineTotal, self::STATUS_PENDING]);
                $orderId = (int) $pdo->lastInsertId();

                $created[] = [
                    'id'          => $orderId,
                    'product_id'  => $productId,
                    'title'       => $row['title'],
                    'quantity'    => 1,
                    'total_price' => $lineTotal,
                    'status'      => self::STATUS_PENDING,
                ];
            }

            CartItem::removeManyInTx($pdo, $buyerId, array_map(
                static fn (array $r): int => (int) $r['id'],
                $cart
            ));

            return ['orders' => $created, 'total' => round($grandTotal, 2)];
        });
    }

    public static function view(array $o): array
    {
        return [
            'id'            => (int) $o['id'],
            'buyer_id'      => (int) $o['buyer_id'],
            'buyer_name'    => $o['buyer_name'] ?? null,
            'product_id'    => (int) $o['product_id'],
            'title'         => $o['title'] ?? null,
            'image_path'    => $o['image_path'] ?? null,
            'seller_id'     => isset($o['seller_id']) ? (int) $o['seller_id'] : null,
            'seller_name'   => $o['seller_name'] ?? null,
            'quantity'      => (int) $o['quantity'],
            'total_price'   => (float) $o['total_price'],
            'status'        => $o['status'],
            'order_date'    => $o['order_date'],
            'reviewed'      => isset($o['review_id']) && $o['review_id'] !== null,
            'review_rating' => isset($o['review_rating']) ? (int) $o['review_rating'] : null,
        ];
    }
}
