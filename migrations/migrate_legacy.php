<?php
/**
 * 把旧库 db_01 的 100 件商品迁移到新库 online_marketplace。
 *
 * 用法（在 WSL 里）:
 *     php migrations/migrate_legacy.php            # 迁移
 *     php migrations/migrate_legacy.php --dry-run  # 只看统计，不写入
 *
 * 为什么单独写成 PHP 而不是 .sql：
 *   1. db_01 可能不存在（新环境上不会有），需要优雅跳过而不是报错中断
 *   2. 分类 ID 和状态值都要做映射，用代码写比 SQL 清楚
 *   3. 原表的排序键 created_at 100 行全是同一个值 —— 迁移时特意错开，
 *      从根上避免「分页丢商品」那类问题再出现
 *
 * 字段映射:
 *   db_01.products.name        → online_marketplace.products.title
 *   db_01.products.description → 原样
 *   db_01.products.price       → 原样
 *   db_01.products.category_id → 映射（见下）
 *   db_01.products.image_path  → 原样
 *   （没有 status / seller_id / is_sold，由本脚本补齐）
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

$dryRun = in_array('--dry-run', $argv, true);

// 旧分类 ID → 新分类 ID
//   旧: 1电子产品 2服装 3家居用品 4食品饮料 5图书
//   新: 1图书 2电子产品 3学习用品 4服装 5家居用品 6食品饮料
const CATEGORY_MAP = [1 => 2, 2 => 4, 3 => 5, 4 => 6, 5 => 1];

const LEGACY_DB = 'db_01';
const TARGET_DB = 'online_marketplace';

$pdo = Database::conn();

/** 检查某个库是否存在 */
function databaseExists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?'
    );
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "旧库迁移：db_01 → online_marketplace\n";
echo str_repeat('-', 52) . "\n";

if (!databaseExists($pdo, LEGACY_DB)) {
    echo "  跳过：找不到数据库 `" . LEGACY_DB . "`（新环境属于正常情况，无需迁移）\n";
    exit(0);
}

// 旧表结构里没有 status，先确认它到底有没有被 ALTER 加过
$hasStatus = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = '" . LEGACY_DB . "'
        AND table_name = 'products'
        AND column_name = 'status'"
)->fetchColumn() > 0;

$legacyRows = $pdo->query(
    'SELECT id, name, description, price, category_id, image_path, created_at
       FROM `' . LEGACY_DB . '`.products ORDER BY id'
)->fetchAll();

echo "  旧库商品数: " . count($legacyRows) . "\n";
echo "  旧表有 status 列: " . ($hasStatus ? '是' : '否') . "\n";

// 已迁移过就不要再插一遍（按标题+卖家判断太脆弱，这里用迁移标记表）
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `_migrations` (
        `name`       varchar(100) NOT NULL,
        `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`name`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$already = (int) $pdo->query(
    "SELECT COUNT(*) FROM `_migrations` WHERE name = 'legacy_db_01_products'"
)->fetchColumn() > 0;

if ($already) {
    echo "  跳过：这批数据之前已经迁移过了（_migrations 里有记录）\n";
    echo "  如需强制重迁，先执行: DELETE FROM `_migrations` WHERE name='legacy_db_01_products';\n";
    exit(0);
}

if ($dryRun) {
    echo "\n  --dry-run 模式，不写入。预览前 3 条：\n";
    foreach (array_slice($legacyRows, 0, 3) as $r) {
        $newCat = CATEGORY_MAP[(int) $r['category_id']] ?? null;
        echo sprintf(
            "    #%d %s → 分类 %s→%s, %s\n",
            $r['id'], $r['name'], $r['category_id'], $newCat ?? 'NULL', $r['price']
        );
    }
    exit(0);
}

// ---------- 正式迁移 ----------
$sellerIds = $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if ($sellerIds === []) {
    fwrite(STDERR, "  失败：目标库还没有用户，请先跑 002_seed.sql\n");
    exit(1);
}

// 迁移过来的这批描述都是「全新行货」，按 id 确定性地分配状态和卖家，
// 这样状态筛选才有多样性可看（不随机的，可重复执行结果一致）
$statusFor = static function (int $id): string {
    if ($id % 10 === 0) return 'damaged';
    if ($id % 3 === 0)  return 'new';
    return 'used';
};
$sellerFor = static fn (int $id): int => (int) $sellerIds[$id % count($sellerIds)];

$insert = $pdo->prepare(
    'INSERT INTO products
       (title, description, price, category_id, status, image_path, seller_id, created_at, is_sold)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
);

$pdo->beginTransaction();
try {
    $n = 0;
    $skipped = 0;
    foreach ($legacyRows as $row) {
        $id = (int) $row['id'];
        $cat = CATEGORY_MAP[(int) $row['category_id']] ?? null;

        // 标题已存在就跳过（避免重复执行插入两份）
        $dup = $pdo->prepare('SELECT 1 FROM products WHERE title = ? LIMIT 1');
        $dup->execute([$row['name']]);
        if ($dup->fetchColumn()) {
            $skipped++;
            continue;
        }

        // ★ 错开 created_at。原表 100 行的时间戳完全相同，正是分页错乱的根源。
        //   这里按 id 拉开到不同的分钟，保证排序键有区分度。
        $createdAt = date('Y-m-d H:i:s', strtotime('2025-03-30 04:28:47') + $id * 1800);

        $insert->execute([
            $row['name'],
            $row['description'],
            $row['price'],
            $cat,
            $statusFor($id),
            $row['image_path'],
            $sellerFor($id),
            $createdAt,
        ]);
        $n++;
    }

    $pdo->prepare('INSERT INTO `_migrations` (name) VALUES (?)')
        ->execute(['legacy_db_01_products']);
    $pdo->commit();

    echo "  已迁移 {$n} 条";
    if ($skipped > 0) {
        echo "，跳过 {$skipped} 条（标题重复）";
    }
    echo "\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, '  迁移失败，已回滚：' . $e->getMessage() . "\n");
    exit(1);
}

$total = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
echo str_repeat('-', 52) . "\n";
echo "  新库 products 总数: {$total}\n";
