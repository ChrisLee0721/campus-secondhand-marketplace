<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;

/**
 * 站点统计。给首页横幅用。
 *
 * 刻意做成一个接口而不是把数字写死在 HTML 里 —— 横幅上显示的必须是真实数字，
 * 不然「在售 137 件」这种文案过两天就不对了。
 */
final class Stats
{
    public static function summary(): array
    {
        $pdo = Database::conn();

        // 一次查询把几个 COUNT 都算出来，避免来回建连接
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
