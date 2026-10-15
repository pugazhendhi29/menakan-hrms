<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Native prepared statements only; table and column names
 * passed to insert()/update() always come from code, never from user input.
 */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('database');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset']);
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach (array_values(array_is_list($params) ? $params : []) as $i => $v) {
            $stmt->bindValue($i + 1, $v, self::type($v));
        }
        if (!array_is_list($params)) {
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . ltrim((string) $k, ':'), $v, self::type($v));
            }
        }
        $stmt->execute();
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function column(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn ($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(',', array_map(fn ($c) => "`$c` = ?", array_keys($data)));
        $stmt = self::query("UPDATE `$table` SET $set WHERE $where", array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    /** @template T @param callable():T $fn @return T */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Builds "?, ?, ?" for an IN () list. */
    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }

    private static function type(mixed $v): int
    {
        return match (true) {
            is_int($v)  => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_BOOL,
            $v === null => PDO::PARAM_NULL,
            default     => PDO::PARAM_STR,
        };
    }
}
