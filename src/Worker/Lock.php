<?php

declare(strict_types=1);

namespace WishVault\Worker;

use WishVault\Clock;
use WishVault\Db\Database;
use WishVault\Support\Data;

/**
 * Worker lock in `jobs_state` (key `lock`), so two worker processes never
 * run jobs at the same time. The holder refreshes it; it expires if the
 * holder dies.
 */
final readonly class Lock
{
    public function __construct(
        private \PDO $pdo,
        private Clock $clock,
    ) {
    }

    public function acquire(string $owner, int $ttlSeconds): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $raw = Database::run($this->pdo, "SELECT value FROM jobs_state WHERE key = 'lock'")->fetchColumn();
            $current = is_string($raw) ? json_decode($raw, true) : null;
            $holder = Data::str($current, 'owner');
            $expires = Data::int($current, 'expires_at') ?? 0;
            if ($holder !== null && $holder !== $owner && $expires > $now) {
                $this->pdo->exec('COMMIT');
                return false;
            }
            Database::run(
                $this->pdo,
                "INSERT INTO jobs_state (key, value, updated_at) VALUES ('lock', :v, :t)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
                [
                    'v' => json_encode(['owner' => $owner, 'expires_at' => $now + $ttlSeconds], JSON_THROW_ON_ERROR),
                    't' => gmdate('Y-m-d\TH:i:s\Z', $now),
                ],
            );
            $this->pdo->exec('COMMIT');
            return true;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public function release(string $owner): void
    {
        Database::run(
            $this->pdo,
            "DELETE FROM jobs_state WHERE key = 'lock' AND json_extract(value, '$.owner') = :o",
            ['o' => $owner],
        );
    }
}
