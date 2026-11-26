<?php

declare(strict_types=1);

namespace WishVault\Db;

final class Database
{
    public static function open(string $path): \PDO
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0o770, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create data directory');
            }
        }
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        Schema::migrate($pdo);
        return $pdo;
    }

    /**
     * Prepared query helper (PDO is in exception mode; false never comes back,
     * this only narrows the type). Integers are bound as integers so that
     * comparisons with JSON values and LIMIT work as expected.
     *
     * @param array<string, scalar|null> $params
     */
    public static function run(\PDO $pdo, string $sql, array $params = []): \PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed');
        }
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, is_bool($value) ? (int) $value : $value, match (true) {
                is_int($value), is_bool($value) => \PDO::PARAM_INT,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            });
        }
        $stmt->execute();
        return $stmt;
    }
}
