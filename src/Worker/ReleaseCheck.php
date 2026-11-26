<?php

declare(strict_types=1);

namespace WishVault\Worker;

use WishVault\Clock;
use WishVault\Config;
use WishVault\Db\Database;
use WishVault\Events\Events;
use WishVault\Http\ApiError;
use WishVault\Support\Data;
use WishVault\SystemClock;
use WishVault\Upstream\Steam;

/**
 * Re-checks unreleased Steam wishes; a switch to "released" creates one
 * `released` event per interested account.
 */
final readonly class ReleaseCheck
{
    public const int MAX_PER_RUN = 40;
    public const int PAUSE_MS = 300;

    /**
     * @param \Closure(int): void $sleepMs
     */
    public function __construct(
        private Config $config,
        private \PDO $pdo,
        private Steam $steam,
        private Events $events,
        private Clock $clock,
        private \Closure $sleepMs,
    ) {
    }

    /** Returns how many wishes were checked. */
    public function run(): int
    {
        $now = $this->clock->now();
        $cutoff = SystemClock::format($now->modify('-' . $this->config->steamRecheckHours . ' hours'));
        $rows = Database::run(
            $this->pdo,
            "SELECT id, steam_app_id, release_date FROM wishes
             WHERE coming_soon = 1 AND status <> 'rejected' AND steam_app_id IS NOT NULL
               AND (details_checked_at IS NULL OR details_checked_at < :cutoff)
             ORDER BY details_checked_at ASC, id ASC LIMIT " . self::MAX_PER_RUN,
            ['cutoff' => $cutoff],
        )->fetchAll();
        $checked = 0;
        foreach ($rows as $i => $row) {
            if ($i > 0) {
                ($this->sleepMs)(self::PAUSE_MS);
            }
            $id = (int) Data::int($row, 'id');
            try {
                $details = $this->steam->appDetails((int) Data::int($row, 'steam_app_id'), useCache: false);
            } catch (ApiError) {
                break; // Steam unavailable or throttling: try again next run
            }
            $stamp = SystemClock::format($this->clock->now());
            $checked++;
            if ($details === null) {
                Database::run($this->pdo, 'UPDATE wishes SET details_checked_at = :t WHERE id = :id', ['t' => $stamp, 'id' => $id]);
                continue;
            }
            $released = !$details['coming_soon'];
            Database::run(
                $this->pdo,
                'UPDATE wishes SET details_checked_at = :t, release_date = :r, coming_soon = :c,
                    updated_at = CASE WHEN :c = 0 OR release_date IS NOT :r THEN :t ELSE updated_at END
                 WHERE id = :id',
                ['t' => $stamp, 'r' => $details['release_date'], 'c' => $released ? 0 : 1, 'id' => $id],
            );
            if ($released) {
                $this->events->notifyInterested($id, 'released');
            }
        }
        return $checked;
    }
}
