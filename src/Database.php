<?php
declare(strict_types=1);

namespace App;

use App\Http\ApiException;
use PDO;
use PDOException;

/**
 * PDO 单例。
 *
 * 全项目只在这里建连接。上一版把连接散在 db_connect.php 里、错误信息直接
 * echo 给用户，这里统一：连不上抛 500，细节只进 error_log。
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
                // 关掉模拟预处理，让 MySQL 真正做预处理 —— 类型更严格
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connect failed: ' . $e->getMessage());
            throw new ApiException(500, 'database_unavailable', '数据库连接失败');
        }

        return self::$pdo;
    }

    /**
     * 在一个事务里跑回调。抛异常自动回滚。
     *
     * 下单这种"扣库存 + 写订单 + 清购物车"的多步操作必须走这里，
     * 否则中途失败会留下一半写入的数据。
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

    /** 测试/脚本用：强制丢弃当前连接。 */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
