<?php

declare(strict_types=1);

namespace WishVault\Worker;

use WishVault\Clock;
use WishVault\Config;
use WishVault\Db\Cache;
use WishVault\Db\Database;
use WishVault\Db\JobsState;
use WishVault\Events\Events;
use WishVault\Http\ApiError;
use WishVault\Library\Matcher;
use WishVault\Security\RateLimiter;
use WishVault\SystemClock;
use WishVault\Upstream\GameVault;
use WishVault\Upstream\HttpClient;
use WishVault\Upstream\Steam;

/**
 * Background jobs without cron. `tick()` runs every 10 seconds: heartbeat,
 * lock, then whatever is due (sync on request or every SYNC_SECONDS, release
 * check every STEAM_RECHECK_HOURS, hourly purge, daily backup).
 */
final class Worker
{
    public const int TICK_SECONDS = 10;
    public const int LOCK_TTL = 600;

    private readonly JobsState $state;
    private readonly Lock $lock;
    private readonly GameVault $gameVault;
    private readonly Steam $steam;
    private readonly Events $events;
    private readonly Cache $cache;

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /**
     * @param \Closure(int): void $sleepMs
     * @param (\Closure(string): void)|null $log defaults to error_log
     */
    public function __construct(
        private readonly Config $config,
        private readonly \PDO $pdo,
        HttpClient $http,
        private readonly Clock $clock,
        private readonly \Closure $sleepMs,
        private readonly string $owner,
        ?\Closure $log = null,
    ) {
        $this->log = $log ?? static function (string $message): void {
            error_log('wishvault worker: ' . $message);
        };
        $this->state = new JobsState($pdo, $clock);
        $this->lock = new Lock($pdo, $clock);
        $this->cache = new Cache($pdo, $clock);
        // one GameVault instance per process: the bot token stays in memory
        $this->gameVault = new GameVault($config, $http, $this->cache, $clock);
        $this->steam = new Steam($config, $http, $this->cache);
        $this->events = new Events($pdo, $clock);
    }

    /**
     * @return list<string> jobs that ran
     */
    public function tick(): array
    {
        $this->heartbeat();
        if (!$this->lock->acquire($this->owner, self::LOCK_TTL)) {
            return [];
        }
        $ran = [];
        $requested = $this->state->get('sync_requested');
        if ($requested !== null || $this->due('last_sync_started', $this->config->syncSeconds)) {
            $this->sync($requested);
            $ran[] = 'sync';
        }
        if ($this->due('last_release_check', $this->config->steamRecheckHours * 3600)) {
            $this->releaseCheck();
            $ran[] = 'release_check';
        }
        if ($this->due('last_purge', 3600)) {
            $this->purge();
            $ran[] = 'purge';
        }
        if ($this->due('last_backup', 86400)) {
            $this->backup();
            $ran[] = 'backup';
        }
        return $ran;
    }

    /**
     * Loops until `$stop` returns true.
     *
     * @param \Closure(): bool $stop
     */
    public function run(\Closure $stop): void
    {
        while (!self::stopped($stop)) {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                $this->log('tick failed: ' . $e::class);
            }
            for ($i = 0; $i < self::TICK_SECONDS && !self::stopped($stop); $i++) {
                ($this->sleepMs)(1000);
            }
        }
        $this->releaseLock();
    }

    public function releaseLock(): void
    {
        $this->lock->release($this->owner);
    }

    /**
     * @param \Closure(): bool $stop
     * @phpstan-impure
     */
    private static function stopped(\Closure $stop): bool
    {
        return $stop();
    }

    public function sync(mixed $requested = null): void
    {
        $started = $this->now();
        $this->state->set('last_sync_started', $started);
        $result = ['started_at' => $started, 'finished_at' => null, 'ok' => false, 'error' => null, 'games' => 0, 'matched' => 0];
        $this->state->set('last_sync', $result);
        try {
            $sync = new LibrarySync(
                $this->config,
                $this->pdo,
                $this->gameVault,
                new Matcher($this->pdo),
                $this->events,
                $this->clock,
                function (): void {
                    $this->heartbeat();
                    $this->lock->acquire($this->owner, self::LOCK_TTL);
                },
            );
            $counts = $sync->run();
            $result = ['ok' => true] + $counts + $result;
            $this->state->set('last_sync_ok_at', $this->now());
            $this->rememberProvider();
        } catch (ApiError $e) {
            $result['error'] = $e->errorCode;
            $this->log('sync failed: ' . $e->errorCode);
        } catch (\Throwable $e) {
            $result['error'] = 'internal_error';
            $this->log('sync failed: ' . $e::class);
        }
        $result['finished_at'] = $this->now();
        $this->state->set('last_sync', $result);
        if ($requested !== null) {
            // keep a request that arrived while the sync was running
            Database::run(
                $this->pdo,
                "DELETE FROM jobs_state WHERE key = 'sync_requested' AND value = :v",
                ['v' => json_encode($requested, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            );
        }
    }

    /**
     * Name of the active metadata provider for /info, so clients can label
     * the provider search before the first provider request.
     */
    private function rememberProvider(): void
    {
        if (!$this->config->providerSearchEnabled()) {
            $this->state->set('provider', null);
            return;
        }
        try {
            $this->state->set('provider', $this->gameVault->activeProvider());
        } catch (ApiError $e) {
            // keep the last known name, the next sync tries again
            $this->log('provider lookup failed: ' . $e->errorCode);
        }
    }

    public function releaseCheck(): void
    {
        try {
            (new ReleaseCheck($this->config, $this->pdo, $this->steam, $this->events, $this->clock, $this->sleepMs))->run();
        } catch (\Throwable $e) {
            $this->log('release check failed: ' . $e::class);
        }
        $this->state->set('last_release_check', $this->now());
    }

    public function purge(): void
    {
        $now = $this->now();
        $this->cache->purge();
        (new RateLimiter($this->pdo, $this->clock))->purge();
        Database::run($this->pdo, 'DELETE FROM sessions WHERE expires_at <= :now', ['now' => $now]);
        // anonymous visitors without votes who did not come back for 90 days
        Database::run(
            $this->pdo,
            'DELETE FROM voters WHERE last_seen_at < :cutoff AND NOT EXISTS (SELECT 1 FROM votes WHERE voter_id = voters.id)',
            ['cutoff' => SystemClock::format($this->clock->now()->modify('-90 days'))],
        );
        $this->state->set('last_purge', $now);
    }

    public function backup(): void
    {
        try {
            (new Backup($this->pdo, $this->config->dataDir . '/backups', $this->clock))->run();
        } catch (\Throwable $e) {
            $this->log('backup failed: ' . $e::class);
        }
        $this->state->set('last_backup', $this->now());
    }

    private function heartbeat(): void
    {
        $this->state->set('worker_seen', $this->now());
    }

    private function due(string $key, int $intervalSeconds): bool
    {
        $last = $this->state->time($key);
        return $last === null || $last->getTimestamp() + $intervalSeconds <= $this->clock->now()->getTimestamp();
    }

    private function now(): string
    {
        return SystemClock::format($this->clock->now());
    }

    private function log(string $message): void
    {
        ($this->log)($message);
    }
}
