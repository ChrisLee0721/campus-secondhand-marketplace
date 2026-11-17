<?php
/**
 * Migrate the 100 listings from the legacy db_01 database into online_marketplace.
 *
 * Usage (inside WSL):
 *     php migrations/migrate_legacy.php            # migrate
 *     php migrations/migrate_legacy.php --dry-run  # statistics only, no writes
 *
 * Why PHP instead of plain SQL:
 *   1. db_01 may not exist (a fresh environment will not have it) and must be skipped gracefully
 *   2. category ids and status values need mapping, which is clearer in code than in SQL
 *   3. the original sort key created_at is identical for all 100 rows; the migration spreads it
 *      so the paging bug caused by that cannot come back
 *
 * Field mapping:
 *   db_01.products.name        → online_marketplace.products.title
 *   db_01.products.description -> as is
 *   db_01.products.price       -> as is
 *   db_01.products.category_id -> mapped (see below)
 *   db_01.products.image_path  -> as is
 *   (status / seller_id / is_sold are added by this script)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

$dryRun = in_array('--dry-run', $argv, true);

// legacy category id -> new category id
//   old: 1 electronics 2 apparel 3 home 4 food 5 books
//   new: 1 books 2 electronics 3 study 4 apparel 5 home 6 food
const CATEGORY_MAP = [1 => 2, 2 => 4, 3 => 5, 4 => 6, 5 => 1];

const LEGACY_DB = 'db_01';
const TARGET_DB = 'online_marketplace';

$pdo = Database::conn();

/** Check whether a database exists */
function databaseExists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?'
    );
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "Legacy migration: db_01 -> online_marketplace\n";
echo str_repeat('-', 52) . "\n";

if (!databaseExists($pdo, LEGACY_DB)) {
    echo "  skipped: database `" . LEGACY_DB . "` not found (normal on a fresh environment)\n";
    exit(0);
}

// the legacy table has no status column; check whether it was ever altered
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

echo "  legacy listings: " . count($legacyRows) . "\n";
echo "  legacy table has a status column: " . ($hasStatus ? 'yes' : 'no') . "\n";

// never insert twice; title plus seller would be fragile, so use the migration marker table
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
    echo "  skipped: this batch was migrated before (row present in _migrations)\n";
    echo "  to force it again run: DELETE FROM `_migrations` WHERE name='legacy_db_01_products';\n";
    exit(0);
}

if ($dryRun) {
    echo "\n  --dry-run, nothing written. First 3 rows:\n";
    foreach (array_slice($legacyRows, 0, 3) as $r) {
        $newCat = CATEGORY_MAP[(int) $r['category_id']] ?? null;
        echo sprintf(
            "    #%d %s -> category %s->%s, %s\n",
            $r['id'], $r['name'], $r['category_id'], $newCat ?? 'NULL', $r['price']
        );
    }
    exit(0);
}

// ---------- real migration ----------
$sellerIds = $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if ($sellerIds === []) {
    fwrite(STDERR, "  failed: the target database has no users yet, run 002_seed.sql first\n");
    exit(1);
}

// every migrated description says the item is brand new, so assign status and seller
// deterministically from the id instead (keeps status filters varied and the run repeatable)
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

        // skip when the title already exists (prevents duplicate inserts)
        $dup = $pdo->prepare('SELECT 1 FROM products WHERE title = ? LIMIT 1');
        $dup->execute([$row['name']]);
        if ($dup->fetchColumn()) {
            $skipped++;
            continue;
        }

        // Stagger created_at. All 100 legacy rows share one timestamp, which caused the paging bug.
        //    Spread them across minutes by id so the sort key has discrimination.
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

    echo "  migrated {$n} rows";
    if ($skipped > 0) {
        echo ", skipped {$skipped} (duplicate titles)";
    }
    echo "\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, '  migration failed, rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}

$total = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
echo str_repeat('-', 52) . "\n";
echo "  products in the new database: {$total}\n";
