<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Product model. Table: products
 * (id, title, description, price, category_id, status, image_path, seller_id, created_at, is_sold)
 */
final class Product extends Model
{
    protected static string $table = 'products';

    protected static array $fillable = [
        'title', 'description', 'price', 'category_id', 'status', 'image_path', 'seller_id',
        'image_blob', 'image_mime',
    ];

    /** Map of the Chinese status values migrated from drop_01 to the new enum */
    public const STATUS_NEW     = 'new';
    public const STATUS_USED    = 'used';
    public const STATUS_DAMAGED = 'damaged';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_USED, self::STATUS_DAMAGED];

    /**
     * Filtered, paginated query.
     *
     * Key fix: ORDER BY now has the tie-breaker p.id DESC.
     *   The old version only sorted by p.created_at DESC, and in the seed data hundreds of
     *   rows share one created_at (COUNT(DISTINCT created_at) = 1), so the sort key had
     *   no discrimination, MySQL returned an undefined order and paging repeated or lost rows.
     *   Measured: nine pages returned 71 distinct products, 29 were unreachable and page 1 overlapped page 2 by 11 rows.
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
            $where[] = '(p.category_id = :category_id OR p.category_id IN (SELECT id FROM categories WHERE parent_id = :category_parent))';
            $params[':category_id'] = (int) $filters['category_id'];
            $params[':category_parent'] = (int) $filters['category_id'];
        }

        if (!empty($filters['seller_id'])) {
            $where[] = 'p.seller_id = :seller_id';
            $params[':seller_id'] = (int) $filters['seller_id'];
        }

        if (!empty($filters['search'])) {
            // The keyword matches both title and description.
            // The connection sets EMULATE_PREPARES=false, so this is a native MySQL prepared
            // statement, and those may not reuse a named placeholder (SQLSTATE[HY093]
            // Invalid parameter number). Two distinct placeholders bound to the same value are
            // therefore required here.
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

        // The public list always excludes listings hidden by an admin
        $where[] = 'p.is_hidden = 0';

        // Only items on sale by default; ?include_sold=1 shows everything
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

        $sql = "SELECT p.id, p.title, p.description, p.price, p.category_id, p.status,
                       p.image_path, p.seller_id, p.created_at, p.is_sold,
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

    /** One listing with its category name and seller details. */
    public static function findDetailed(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT p.id, p.title, p.description, p.price, p.category_id, p.status,
                    p.image_path, p.seller_id, p.created_at, p.is_sold, p.is_hidden,
                    c.name AS category_name,
                    u.username AS seller_name, u.avatar_path AS seller_avatar_path,
                    u.seller_level, u.last_active_at AS seller_last_active_at,
                    u.meetup_address AS seller_meetup_address
               FROM products p
               LEFT JOIN categories c ON p.category_id = c.id
               LEFT JOIN users      u ON p.seller_id  = u.id
              WHERE p.id = ? AND p.is_hidden = 0'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Atomically mark a listing as sold (conditional update, prevents double checkout). */
    public static function markSold(int $id): bool
    {
        $stmt = static::db()->prepare('UPDATE products SET is_sold = 1 WHERE id = ? AND is_sold = 0');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /** Admin view: paginate every listing including the seller. */
    public static function adminPaginate(int $page, int $perPage, string $status = ''): array
    {
        $where = '';
        if ($status === 'available') {
            $where = 'WHERE p.is_hidden = 0 AND p.is_sold = 0';
        } elseif ($status === 'sold') {
            $where = 'WHERE p.is_sold = 1';
        } elseif ($status === 'hidden') {
            $where = 'WHERE p.is_hidden = 1';
        }

        $countStmt = static::db()->prepare("SELECT COUNT(*) FROM products p {$where}");
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $stmt = static::db()->prepare(
            "SELECT p.id, p.title, p.price, p.image_path, p.is_sold, p.is_hidden, p.created_at, p.seller_id,
                    u.username AS seller_name, u.email AS seller_email
               FROM products p
               JOIN users u ON p.seller_id = u.id
               {$where}
              ORDER BY p.id DESC
              LIMIT :limit OFFSET :offset"
        );
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

    public static function adminFind(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT p.id, p.title, p.price, p.image_path, p.is_sold, p.is_hidden, p.created_at, p.seller_id,
                    u.username AS seller_name, u.email AS seller_email
               FROM products p
               JOIN users u ON p.seller_id = u.id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function adminView(array $p): array
    {
        return [
            'id'           => (int) $p['id'],
            'title'        => $p['title'],
            'price'        => (float) $p['price'],
            'image_path'   => $p['image_path'] ?? null,
            'is_sold'      => (bool) ($p['is_sold'] ?? false),
            'is_hidden'    => (bool) ($p['is_hidden'] ?? false),
            'seller_id'    => (int) $p['seller_id'],
            'seller_name'  => $p['seller_name'] ?? null,
            'seller_email' => $p['seller_email'] ?? null,
            'created_at'   => $p['created_at'],
        ];
    }

    public static function adminSetHidden(int $id, bool $hidden): void
    {
        $stmt = static::db()->prepare('UPDATE products SET is_hidden = ? WHERE id = ?');
        $stmt->execute([(int) $hidden, $id]);
    }

    /** Admin delete: removes the listing and its orders, bypassing seller protection. */
    public static function adminDelete(int $id): void
    {
        \App\Database::transaction(static function (PDO $pdo) use ($id): void {
            $pdo->prepare('DELETE FROM orders WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        });
    }

    /** Live listings of one seller. */
    public static function countBySeller(int $sellerId): int
    {
        $stmt = static::db()->prepare('SELECT COUNT(*) FROM products WHERE seller_id = ? AND is_sold = 0 AND is_hidden = 0');
        $stmt->execute([$sellerId]);
        return (int) $stmt->fetchColumn();
    }

    /** Sold listings of one seller. */
    public static function countSoldBySeller(int $sellerId): int
    {
        $stmt = static::db()->prepare('SELECT COUNT(*) FROM products WHERE seller_id = ? AND is_sold = 1 AND is_hidden = 0');
        $stmt->execute([$sellerId]);
        return (int) $stmt->fetchColumn();
    }

    /** Output shape: decimal values become floats and flags become real booleans. */
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
            'seller_last_active_at' => $p['seller_last_active_at'] ?? null,
            'seller_meetup_address' => $p['seller_meetup_address'] ?? null,
            'is_sold'       => (bool) $p['is_sold'],
            'created_at'    => $p['created_at'],
        ];
    }
}
