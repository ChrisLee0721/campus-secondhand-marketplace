<?php
declare(strict_types=1);

namespace App;

use App\Http\ApiException;
use PDO;
use PDOException;

/**
 * PDO singleton.
 *
 * The only place that opens a connection. The previous version scattered connections across
 * db_connect.php and echoed errors to the client; here a failure becomes a 500 and details go to error_log.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // disable emulated prepares so MySQL really prepares statements: stricter typing
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connect failed: ' . $e->getMessage());
            throw new ApiException(500, 'database_unavailable', 'Database connection failed');
        }

        return self::$pdo;
    }

    /**
     * Run a callback inside a transaction. Any exception rolls back.
     *
     * Multi-step writes such as checkout (claim the item, write the order, clear the cart) must go through here,
     * otherwise a failure half way leaves the data half written.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::conn();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Testing and scripts only: drop the current connection. */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
