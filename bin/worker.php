<?php

declare(strict_types=1);

/*
 * Background worker, started by s6 inside the container (no cron).
 * Runs forever: heartbeat + sync flag check every 10 s, GameVault sync every
 * SYNC_SECONDS, Steam release check every STEAM_RECHECK_HOURS.
 *
 * Track S fills in the jobs; this skeleton only keeps the heartbeat.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use WishVault\Config;
use WishVault\Db\Database;
use WishVault\SystemClock;

Config::loadDotEnv(dirname(__DIR__) . '/.env');
$config = Config::fromEnv();
$clock = new SystemClock();
$pdo = Database::open($config->databasePath());
$args = $_SERVER['argv'] ?? [];
$once = is_array($args) && in_array('--once', $args, true);

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

fwrite(STDOUT, "wishvault worker started\n");
do {
    $now = SystemClock::format($clock->now());
    $pdo->prepare(
        "INSERT INTO jobs_state (key, value, updated_at) VALUES ('worker_seen', :v, :t)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
    )->execute(['v' => json_encode($now), 't' => $now]);

    // TODO(track S): lock, sync flag, GameVault sync, release check, cache purge, backup.

    if ($once) {
        break;
    }
    for ($i = 0; $i < 10 && $running; $i++) {
        sleep(1);
    }
} while ($running);
fwrite(STDOUT, "wishvault worker stopped\n");
