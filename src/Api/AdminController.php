<?php

declare(strict_types=1);

namespace WishVault\Api;

use WishVault\Auth\Auth;
use WishVault\Clock;
use WishVault\Db\Database;
use WishVault\Db\JobsState;
use WishVault\Http\Context;
use WishVault\Http\Response;
use WishVault\Support\Data;
use WishVault\SystemClock;

final readonly class AdminController
{
    public function __construct(
        private Auth $auth,
        private \PDO $pdo,
        private JobsState $state,
        private Clock $clock,
    ) {
    }

    public function sync(Context $ctx): Response
    {
        $this->auth->admin($ctx);
        $now = SystemClock::format($this->clock->now());
        $this->state->set('sync_requested', $now);
        return Response::json(['requested_at' => $now], 202);
    }

    public function status(Context $ctx): Response
    {
        $this->auth->admin($ctx);
        $lastSync = $this->state->get('last_sync');
        $count = fn (string $table): int => (int) Data::int(Database::run($this->pdo, "SELECT COUNT(*) FROM $table")->fetchColumn());
        return Response::json([
            'worker_seen_at' => self::str($this->state->get('worker_seen')),
            'sync_pending' => $this->state->get('sync_requested') !== null,
            'last_sync' => is_array($lastSync) ? [
                'started_at' => Data::str($lastSync, 'started_at') ?? '',
                'finished_at' => Data::str($lastSync, 'finished_at'),
                'ok' => Data::bool($lastSync, 'ok') ?? false,
                'error' => Data::str($lastSync, 'error'),
                'games' => Data::int($lastSync, 'games') ?? 0,
                'matched' => Data::int($lastSync, 'matched') ?? 0,
            ] : null,
            'last_release_check_at' => self::str($this->state->get('last_release_check')),
            'counts' => [
                'wishes' => $count('wishes'),
                'votes' => $count('votes'),
                'anonymous_voters' => $count('voters'),
                'users' => $count('users'),
            ],
        ]);
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
