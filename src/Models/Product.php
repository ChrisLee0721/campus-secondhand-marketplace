<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * 商品模型。表：products
 * (id, title, description, price, category_id, status, image_path, seller_id, created_at, is_sold)
 */
final class Product extends Model
{
    protected static string $table = 'products';

    protected static array $fillable = [
        'title', 'description', 'price', 'category_id', 'status', 'image_path', 'seller_id',
    ];

    /** drop_01 迁移过来的中文状态 → 新库 enum 的映射 */
    public const STATUS_NEW     = 'new';
    public const STATUS_USED    = 'used';
    public const STATUS_DAMAGED = 'damaged';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_USED, self::STATUS_DAMAGED];

    /**
     * 带筛选的分页查询。
     *
     * ★ 关键修复：ORDER BY 加了次级键 p.id DESC。
     *   原实现只有 ORDER BY p.created_at DESC，而 seed 数据里 100 行的
     *   created_at 完全相同（COUNT(DISTINCT created_at) = 1），
     *   排序键没有区分度 → MySQL 返回顺序未定义 → 翻页时商品重复/丢失。
     *   实测：9 页只能拿到 71 条不重复商品，29 条永远翻不到，第1页与第2页重叠 11 条。
     *
     * @param array{category_id?:int, search?:string, min_price?:?float, max_price?:?float,
     *               status?:string, seller_id?:int, include_sold?:bool} $filters
     * @return array{items:array<int,array>, total:int, page:int, per_page:int, total_pages:int}
     */
    public static function paginate(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = :category_id';
            $params[':category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['seller_id'])) {
            $where[] = 'p.seller_id = :seller_id';
            $params[':seller_id'] = (int) $filters['seller_id'];
        }

        if (!empty($filters['search'])) {
            // 关键词同时匹配标题和描述。
            // 注意：因为连接时设了 EMULATE_PREPARES=false，走的是 MySQL 原生预处理，
            // 而原生预处理**不允许复用同一个命名占位符**（会报 SQLSTATE[HY093]
            // Invalid parameter number）。所以这里必须用两个不同的占位符，
            // 绑定同样的值。
            $where[] = '(p.title LIKE :kw_title OR p.description LIKE :kw_desc)';
            $keyword = '%' . str_replace(['%', '_', '\\'], ['\%', '\_', '\\\\'], (string) $filters['search']) . '%';
            $params[':kw_title'] = $keyword;
            $params[':kw_desc']  = $keyword;
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== null) {
            $where[] = 'p.price >= :min_price';
            $params[':min_price'] = (string) $filters['min_price'];
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== null) {
            $where[] = 'p.price <= :max_price';
            $params[':max_price'] = (string) $filters['max_price'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'p.status = :status';
            $params[':status'] = (string) $filters['status'];
        }

        // 默认只看在售商品；?include_sold=1 可以看全部
        if (empty($filters['include_sold'])) {
            $where[] = 'p.is_sold = 0';
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = static::db()->prepare("SELECT COUNT(*) FROM products p {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $sql = "SELECT p.*,
                       c.name AS category_name,
                       u.username AS seller_name,
                       u.avatar_path AS seller_avatar_path
                  FROM products p
                  LEFT JOIN categories c ON p.category_id = c.id
                  LEFT JOIN users      u ON p.seller_id  = u.id
                  {$whereClause}
                  ORDER BY p.created_at DESC, p.id DESC
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

    /** 单个商品，带分类名和卖家信息。 */
    public static function findDetailed(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT p.*, c.name AS category_name,
                    u.username AS seller_name, u.avatar_path AS seller_avatar_path
               FROM products p
               LEFT JOIN categories c ON p.category_id = c.id
               LEFT JOIN users      u ON p.seller_id  = u.id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** 原子地把商品标记为已售（带条件更新，避免并发重复下单）。 */
    public static function markSold(int $id): bool
    {
        $stmt = static::db()->prepare('UPDATE products SET is_sold = 1 WHERE id = ? AND is_sold = 0');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /** 某卖家在售商品数。 */
    public static function countBySeller(int $sellerId): int
    {
        $stmt = static::db()->prepare('SELECT COUNT(*) FROM products WHERE seller_id = ? AND is_sold = 0');
        $stmt->execute([$sellerId]);
        return (int) $stmt->fetchColumn();
    }

    /** 某卖家已售商品数。 */
    public static function countSoldBySeller(int $sellerId): int
    {
        $stmt = static::db()->prepare('SELECT COUNT(*) FROM products WHERE seller_id = ? AND is_sold = 1');
        $stmt->execute([$sellerId]);
        return (int) $stmt->fetchColumn();
    }

    /** 输出表示：把 decimal 转成 float，bool 转成真 bool。 */
    public static function view(array $p): array
    {
        return [
            'id'            => (int) $p['id'],
            'title'         => $p['title'],
            'description'   => $p['description'],
            'price'         => (float) $p['price'],
            'category_id'   => $p['category_id'] !== null ? (int) $p['category_id'] : null,
            'category_name' => $p['category_name'] ?? null,
            'status'        => $p['status'],
            'image_path'    => $p['image_path'],
            'seller_id'     => (int) $p['seller_id'],
            'seller_name'   => $p['seller_name'] ?? null,
            'seller_avatar_path' => $p['seller_avatar_path'] ?? null,
            'is_sold'       => (bool) $p['is_sold'],
            'created_at'    => $p['created_at'],
        ];
    }
}
