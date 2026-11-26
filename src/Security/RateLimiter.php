<?php

declare(strict_types=1);

namespace WishVault\Security;

use WishVault\Clock;
use WishVault\Db\Database;
use WishVault\Http\ApiError;

/**
 * Sliding-window limits on `rate_hits`. Buckets look like `login:<ip>`,
 * `search:u<id>`, `wish:v<voter>`.
 */
final readonly class RateLimiter
{
    public function __construct(
        private \PDO $pdo,
        private Clock $clock,
    ) {
    }

    /** Throws 429 if the bucket already has `$limit` hits in the window. */
    public function check(string $bucket, int $limit, int $windowSeconds, string $code = 'rate_limited'): void
    {
        $now = $this->clock->now()->getTimestamp();
        $since = $now - $windowSeconds;
        $count = (int) Database::run(
            $this->pdo,
            'SELECT COUNT(*) FROM rate_hits WHERE bucket = :b AND hit_at > :s',
            ['b' => $bucket, 's' => $since],
        )->fetchColumn();
        if ($count < $limit) {
            return;
        }
        // the hit that has to expire before the next request is allowed
        $oldest = Database::run(
            $this->pdo,
            'SELECT hit_at FROM rate_hits WHERE bucket = :b AND hit_at > :s ORDER BY hit_at ASC LIMIT 1 OFFSET :o',
            ['b' => $bucket, 's' => $since, 'o' => $count - $limit],
        )->fetchColumn();
        $retry = is_numeric($oldest) ? (int) $oldest + $windowSeconds - $now : $windowSeconds;
        throw ApiError::rateLimited(max(1, $retry), $code);
    }

    public function hit(string $bucket): void
    {
        Database::run(
            $this->pdo,
            'INSERT INTO rate_hits (bucket, hit_at) VALUES (:b, :t)',
            ['b' => $bucket, 't' => $this->clock->now()->getTimestamp()],
        );
    }

    /** check() + hit() */
    public function attempt(string $bucket, int $limit, int $windowSeconds): void
    {
        $this->check($bucket, $limit, $windowSeconds);
        $this->hit($bucket);
    }

    public function clear(string $bucket): void
    {
        Database::run($this->pdo, 'DELETE FROM rate_hits WHERE bucket = :b', ['b' => $bucket]);
    }

    public function purge(int $olderThanSeconds = 86400): int
    {
        return Database::run(
            $this->pdo,
            'DELETE FROM rate_hits WHERE hit_at <= :t',
            ['t' => $this->clock->now()->getTimestamp() - $olderThanSeconds],
        )->rowCount();
    }
}
