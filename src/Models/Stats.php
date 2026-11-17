<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;

/**
 * Site statistics for the home page banner.
 *
 * Deliberately a model instead of hard-coded numbers in the HTML: the banner must show
 * live figures, otherwise "137 items on sale" is wrong two days later.
 */
final class Stats
{
    public static function summary(): array
    {
        $pdo = Database::conn();

        // One query computes every count, avoiding repeated round trips
        $row = $pdo->query(
            'SELECT
                (SELECT COUNT(*) FROM products)                    AS products_total,
                (SELECT COUNT(*) FROM products WHERE is_sold = 0)  AS products_available,
                (SELECT COUNT(*) FROM products WHERE is_sold = 1)  AS products_sold,
                (SELECT COUNT(*) FROM users)                       AS users_total,
                (SELECT COUNT(*) FROM categories)                  AS categories_total,
                (SELECT COUNT(*) FROM orders)                      AS orders_total'
        )->fetch();

        return [
            'products_total'     => (int) $row['products_total'],
            'products_available' => (int) $row['products_available'],
            'products_sold'      => (int) $row['products_sold'],
            'users_total'        => (int) $row['users_total'],
            'categories_total'   => (int) $row['categories_total'],
            'orders_total'       => (int) $row['orders_total'],
        ];
    }
}
