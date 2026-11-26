<?php

declare(strict_types=1);

namespace WishVault\Db;

use WishVault\Clock;
use WishVault\SystemClock;

/**
 * Worker state in `jobs_state` (JSON values). Known keys:
 * `worker_seen` (heartbeat), `lock`, `sync_requested`, `last_sync`,
 * `last_release_check`, `last_purge`, `last_backup`.
 */
final readonly class JobsState
{
    public function __construct(
        private \PDO $pdo,
        private Clock $clock,
    ) {
    }

    public function get(string $key): mixed
    {
        $value = Database::run($this->pdo, 'SELECT value FROM jobs_state WHERE key = :k', ['k' => $key])->fetchColumn();
        if (!is_string($value)) {
            return null;
        }
        try {
            return json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    public function set(string $key, mixed $value): void
    {
        Database::run(
            $this->pdo,
            'INSERT INTO jobs_state (key, value, updated_at) VALUES (:k, :v, :t)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [
                'k' => $key,
                'v' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                't' => SystemClock::format($this->clock->now()),
            ],
        );
    }

    public function delete(string $key): void
    {
        Database::run($this->pdo, 'DELETE FROM jobs_state WHERE key = :k', ['k' => $key]);
    }

    /** A timestamp stored as JSON string, or null. */
    public function time(string $key): ?\DateTimeImmutable
    {
        $value = $this->get($key);
        if (!is_string($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
