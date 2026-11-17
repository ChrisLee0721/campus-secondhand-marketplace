<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Category model. Table: categories(id, name)
 */
final class Category extends Model
{
    protected static string $table = 'categories';

    protected static array $fillable = ['name'];

    /** @return array<int,array> Every category with its live listing count */
    public static function allWithCounts(): array
    {
        $sql = 'SELECT c.id, c.name, c.parent_id,
                       COUNT(CASE WHEN p.is_sold = 0 AND p.is_hidden = 0 THEN 1 END) AS product_count
                  FROM categories c
                  LEFT JOIN products p ON p.category_id = c.id
              GROUP BY c.id, c.name, c.parent_id
              ORDER BY c.id';
        return static::db()->query($sql)->fetchAll();
    }

    public static function view(array $c): array
    {
        return [
            'id'            => (int) $c['id'],
            'name'          => $c['name'],
            'parent_id'     => isset($c['parent_id']) ? (int) $c['parent_id'] : null,
            'product_count' => isset($c['product_count']) ? (int) $c['product_count'] : null,
        ];
    }
}
