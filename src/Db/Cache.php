<?php

declare(strict_types=1);

namespace WishVault\Db;

use WishVault\Clock;

/**
 * Key/value cache in SQLite with expiry. Values are JSON; `null` is never
 * stored, so a `null` result always means "not cached".
 */
final readonly class Cache
{
    public function __construct(
        private \PDO $pdo,
        private Clock $clock,
    ) {
    }

    public function get(string $key): mixed
    {
        $value = Database::run(
            $this->pdo,
            'SELECT value FROM cache WHERE key = :k AND expires_at > :now',
            ['k' => $key, 'now' => $this->clock->now()->getTimestamp()],
        )->fetchColumn();
        if (!is_string($value)) {
            return null;
        }
        try {
            return json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($value === null) {
            $this->delete($key);
            return;
        }
        Database::run(
            $this->pdo,
            'INSERT INTO cache (key, value, expires_at) VALUES (:k, :v, :e)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at',
            [
                'k' => $key,
                'v' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'e' => $this->clock->now()->getTimestamp() + $ttlSeconds,
            ],
        );
    }

    public function delete(string $key): void
    {
        Database::run($this->pdo, 'DELETE FROM cache WHERE key = :k', ['k' => $key]);
    }

    /** Removes expired entries, returns how many. */
    public function purge(): int
    {
        return Database::run(
            $this->pdo,
            'DELETE FROM cache WHERE expires_at <= :now',
            ['now' => $this->clock->now()->getTimestamp()],
        )->rowCount();
    }
}
