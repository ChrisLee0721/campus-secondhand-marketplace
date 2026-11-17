<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * 商品举报。管理员只处理状态，不自动改变商品可见性。
 */
final class Report extends Model
{
    protected static string $table = 'reports';

    protected static array $fillable = ['reporter_id', 'product_id', 'reason', 'details'];

    public const REASONS = [
        'Prohibited item',
        'Counterfeit',
        'Scam or fraud',
        'Offensive content',
        'Wrong category',
        'Other',
    ];

    public static function pendingExists(int $reporterId, int $productId): bool
    {
        $stmt = static::db()->prepare(
            "SELECT 1 FROM reports WHERE reporter_id = ? AND product_id = ? AND status = 'pending' LIMIT 1"
        );
        $stmt->execute([$reporterId, $productId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function adminPaginate(int $page, int $perPage): array
    {
        $countStmt = static::db()->query('SELECT COUNT(*) FROM reports');
        $total = (int) $countStmt->fetchColumn();
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $stmt = static::db()->prepare(
            'SELECT r.*, p.title AS product_title, u.username AS reporter_name,
                    h.username AS handler_name
               FROM reports r
               JOIN products p ON r.product_id = p.id
               JOIN users u    ON r.reporter_id = u.id
               LEFT JOIN users h ON r.handled_by = h.id
              ORDER BY r.id DESC
              LIMIT :limit OFFSET :offset'
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

    public static function mark(int $id, string $status, int $adminId): void
    {
        $stmt = static::db()->prepare(
            'UPDATE reports SET status = ?, handled_by = ?, handled_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$status, $adminId, $id]);
    }

    public static function adminFind(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT r.*, p.title AS product_title, u.username AS reporter_name,
                    h.username AS handler_name
               FROM reports r
               JOIN products p ON r.product_id = p.id
               JOIN users u    ON r.reporter_id = u.id
               LEFT JOIN users h ON r.handled_by = h.id
              WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function view(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'reporter_id'   => (int) $r['reporter_id'],
            'reporter_name' => $r['reporter_name'] ?? null,
            'product_id'    => (int) $r['product_id'],
            'product_title' => $r['product_title'] ?? null,
            'reason'        => $r['reason'],
            'details'       => $r['details'] ?? null,
            'status'        => $r['status'],
            'handled_by'    => isset($r['handled_by']) ? (int) $r['handled_by'] : null,
            'handler_name'  => $r['handler_name'] ?? null,
            'handled_at'    => $r['handled_at'] ?? null,
            'created_at'    => $r['created_at'],
        ];
    }
}
