<?php
declare(strict_types=1);

namespace App\Models;

use App\Http\ApiException;
use PDO;

/**
 * 购物车。表：cart(id, user_id, product_id, quantity, added_at)
 *
 * 业务规则：
 *  - 同一用户 + 同一商品只有一行（重复加购直接返回已有行）
 *  - 每件二手商品只有一份，cart.quantity 恒为 1
 *  - 不能加自己发布的商品
 *  - 已售出的商品不能加
 */
final class CartItem extends Model
{
    protected static string $table = 'cart';

    protected static array $fillable = ['user_id', 'product_id', 'quantity'];

    /** 列出某用户的购物车，附带商品信息与是否失效。 */
    public static function forUser(int $userId): array
    {
        $sql = 'SELECT ci.id, ci.quantity, ci.added_at,
                       p.id AS product_id, p.title, p.price, p.image_path, p.status,
                       p.is_sold, p.seller_id, c.name AS category_name
                  FROM cart ci
                  JOIN products   p ON ci.product_id = p.id
                  LEFT JOIN categories c ON p.category_id = c.id
                 WHERE ci.user_id = ?
              ORDER BY ci.added_at DESC, ci.id DESC';
        $stmt = static::db()->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function findByProduct(int $userId, int $productId): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM cart WHERE user_id = ? AND product_id = ? LIMIT 1');
        $stmt->execute([$userId, $productId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * 加入购物车。每件二手商品只有一份，所以固定 quantity = 1；
     * 已存在则直接返回，不会重复插入或累加。
     * 返回 ['id' => int, 'quantity' => int, 'created' => bool]
     */
    public static function add(int $userId, int $productId): array
    {
        $product = Product::find($productId);
        if ($product === null) {
            throw ApiException::notFound('商品不存在');
        }
        if ((int) $product['seller_id'] === $userId) {
            throw ApiException::badRequest('不能把自己发布的商品加入购物车');
        }
        if ((int) $product['is_sold'] === 1) {
            throw ApiException::conflict('该商品已售出');
        }

        $existing = self::findByProduct($userId, $productId);

        if ($existing !== null) {
            // 兼容旧数据：历史上 quantity 可能 > 1，这里顺手归一。
            if ((int) $existing['quantity'] !== 1) {
                static::update((int) $existing['id'], ['quantity' => 1]);
            }
            return ['id' => (int) $existing['id'], 'quantity' => 1, 'created' => false];
        }

        $id = static::create([
            'user_id'    => $userId,
            'product_id' => $productId,
            'quantity'   => 1,
        ]);
        return ['id' => $id, 'quantity' => 1, 'created' => true];
    }

    public static function removeForUser(int $id, int $userId): bool
    {
        $stmt = static::db()->prepare('DELETE FROM cart WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function clearForUser(int $userId): void
    {
        $stmt = static::db()->prepare('DELETE FROM cart WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    /** 购物车汇总：明细 + 合计。失效商品（已售/下架）单独标出来，不计入总价。 */
    public static function summary(int $userId): array
    {
        $rows = self::forUser($userId);
        $items = [];
        $total = 0.0;
        $count = 0;

        foreach ($rows as $row) {
            $price = (float) $row['price'];
            $available = (int) $row['is_sold'] === 0;

            $items[] = [
                'id'             => (int) $row['id'],
                'product_id'     => (int) $row['product_id'],
                'title'          => $row['title'],
                'price'          => $price,
                'image_path'     => $row['image_path'],
                'category_name'  => $row['category_name'],
                'status'         => $row['status'],
                // 每件二手商品只有一份，数量恒为 1
                'quantity'       => 1,
                'line_total'     => round($price, 2),
                'available'      => $available,
                'seller_id'      => (int) $row['seller_id'],
                'added_at'       => $row['added_at'],
            ];

            if ($available) {
                $total += $price;
                $count++;
            }
        }

        return [
            'items'      => $items,
            'item_count' => count($items),
            'quantity'   => $count,
            'total'      => round($total, 2),
        ];
    }

    /** 用原生 PDO 在事务中删除（下单成功后清空已结算项）。 */
    public static function removeManyInTx(PDO $pdo, int $userId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND id IN ({$placeholders})");
        $stmt->execute([$userId, ...$ids]);
    }
}
