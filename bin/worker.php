<?php

declare(strict_types=1);

/*
 * Background worker, started by s6 inside the container (no cron).
 * Runs forever: heartbeat + sync flag check every 10 s, GameVault sync every
 * SYNC_SECONDS, Steam release check every STEAM_RECHECK_HOURS, hourly purge,
 * daily backup. `--once` runs a single tick (smoke tests).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use WishVault\Config;
use WishVault\Db\Database;
use WishVault\SystemClock;
use WishVault\Upstream\CurlHttpClient;
use WishVault\Worker\Worker;

Config::loadDotEnv(dirname(__DIR__) . '/.env');
$config = Config::fromEnv();
$args = $_SERVER['argv'] ?? [];
$once = is_array($args) && in_array('--once', $args, true);

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

$worker = new Worker(
    $config,
    Database::open($config->databasePath()),
    new CurlHttpClient(),
    new SystemClock(),
    static function (int $ms): void { usleep($ms * 1000); },
    (gethostname() ?: 'worker') . ':' . getmypid() . ':' . bin2hex(random_bytes(4)),
);

fwrite(STDOUT, "wishvault worker started\n");
if ($once) {
    $worker->tick();
    $worker->releaseLock();
} else {
    $worker->run(static function () use (&$running): bool { return !$running; });
}
fwrite(STDOUT, "wishvault worker stopped\n");
