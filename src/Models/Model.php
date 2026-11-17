<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;
use PDO;

/**
 * Base class for every model: PDO access and the generic query helpers live here.
 */
abstract class Model
{
    protected static string $table = '';

    /** Columns that create/update may write. Stops a request body from setting is_admin. */
    protected static array $fillable = [];

    protected static function db(): PDO
    {
        return Database::conn();
    }

    /** Filter input down to the whitelist. */
    protected static function filter(array $data): array
    {
        return array_intersect_key($data, array_flip(static::$fillable));
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $data = static::filter($data);
        if ($data === []) {
            return 0;
        }
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            static::$table,
            implode(', ', array_map(static fn ($c) => "`{$c}`", $cols)),
            implode(', ', array_map(static fn ($c) => ":{$c}", $cols))
        );
        $stmt = static::db()->prepare($sql);
        foreach ($data as $col => $val) {
            $stmt->bindValue(":{$col}", $val);
        }
        $stmt->execute();
        return (int) static::db()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): bool
    {
        $data = static::filter($data);
        if ($data === []) {
            return false;
        }
        $sets = implode(', ', array_map(static fn ($c) => "`{$c}` = :{$c}", array_keys($data)));
        $sql = sprintf('UPDATE %s SET %s WHERE id = :id', static::$table, $sets);
        $stmt = static::db()->prepare($sql);
        foreach ($data as $col => $val) {
            $stmt->bindValue(":{$col}", $val);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public static function delete(int $id): bool
    {
        $stmt = static::db()->prepare(sprintf('DELETE FROM %s WHERE id = ?', static::$table));
        return $stmt->execute([$id]);
    }

    public static function find(int $id): ?array
    {
        $stmt = static::db()->prepare(sprintf('SELECT * FROM %s WHERE id = ?', static::$table));
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function exists(int $id): bool
    {
        $stmt = static::db()->prepare(sprintf('SELECT 1 FROM %s WHERE id = ?', static::$table));
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }
}
