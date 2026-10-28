<?php
declare(strict_types=1);

namespace App\Models;

/**
 * 分类模型。表：categories(id, name)
 */
final class Category extends Model
{
    protected static string $table = 'categories';

    protected static array $fillable = ['name'];

    /** @return array<int,array> 每个分类附带在售商品数 */
    public static function allWithCounts(): array
    {
        $sql = 'SELECT c.id, c.name,
                       COUNT(CASE WHEN p.is_sold = 0 THEN 1 END) AS product_count
                  FROM categories c
                  LEFT JOIN products p ON p.category_id = c.id
              GROUP BY c.id, c.name
              ORDER BY c.id';
        return static::db()->query($sql)->fetchAll();
    }

    public static function view(array $c): array
    {
        return [
            'id'            => (int) $c['id'],
            'name'          => $c['name'],
            'product_count' => isset($c['product_count']) ? (int) $c['product_count'] : null,
        ];
    }
}
