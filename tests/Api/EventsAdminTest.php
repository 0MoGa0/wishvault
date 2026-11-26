<?php

declare(strict_types=1);

namespace WishVault\Tests\Api;

use WishVault\Tests\TestCase;

final class EventsAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->standardUsers();
        $this->steamApp(620, 'Portal 2');
        $this->steamApp(400, 'Portal');
    }

    public function testEventsFlow(): void
    {
        $app = $this->app();
        self::send($app, 'POST', '/api/v1/wishes', ['source' => 'steam', 'source_id' => '620'], $this->bearer('u1'));
        self::send($app, 'POST', '/api/v1/wishes', ['source' => 'steam', 'source_id' => '400'], $this->bearer('u1'));
        self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'available'], $this->bearer('a1'));
        $this->clock->advance('+1 minute');
        self::send($app, 'PATCH', '/api/v1/wishes/2', ['status' => 'rejected'], $this->bearer('a1'));

        $body = self::body(self::send($app, 'GET', '/api/v1/events', headers: $this->bearer('u1')));
        self::assertSame(2, $body['unread']);
        self::assertSame([
            [
                'id' => 2,
                'type' => 'rejected',
                'wish' => ['id' => 2, 'title' => 'Portal', 'image_url' => 'https://cdn.example.com/400/header.jpg', 'gamevault_game_id' => null],
                'created_at' => '2026-06-01T12:01:00Z',
                'read' => false,
            ],
            [
                'id' => 1,
                'type' => 'available',
                'wish' => ['id' => 1, 'title' => 'Portal 2', 'image_url' => 'https://cdn.example.com/620/header.jpg', 'gamevault_game_id' => null],
                'created_at' => '2026-06-01T12:00:00Z',
                'read' => false,
            ],
        ], $body['items']);
        self::assertSame(2, self::body(self::send($app, 'GET', '/api/v1/me', headers: $this->bearer('u1')))['unread_events']);

        $res = self::send($app, 'POST', '/api/v1/events/read', ['ids' => [1, 99]], $this->bearer('u1'));
        self::assertSame(['unread' => 1], self::body($res));
        $unread = self::body(self::send($app, 'GET', '/api/v1/events?unread=true', headers: $this->bearer('u1')));
        self::assertIsArray($unread['items']);
        self::assertSame([2], array_column($unread['items'], 'id'));

        // another user's ids are ignored
        self::send($app, 'POST', '/api/v1/events/read', ['ids' => [2]], $this->bearer('u2'));
        self::assertSame(['unread' => 0], self::body(self::send($app, 'POST', '/api/v1/events/read', null, $this->bearer('u1'))));
        self::assertSame(0, self::body(self::send($app, 'GET', '/api/v1/events', headers: $this->bearer('u2')))['unread']);

        // events survive a later unvote
        self::send($app, 'DELETE', '/api/v1/wishes/1/vote', headers: $this->bearer('u1'));
        $all = self::body(self::send($app, 'GET', '/api/v1/events', headers: $this->bearer('u1')));
        self::assertIsArray($all['items']);
        self::assertCount(2, $all['items']);
    }

    public function testEventsNeedAnAccount(): void
    {
        $app = $this->app();
        self::assertSame(401, self::send($app, 'GET', '/api/v1/events')->status);
        self::assertSame(401, self::send($app, 'POST', '/api/v1/events/read')->status);
        self::assertSame('role_too_low', self::errorCode(self::send($app, 'GET', '/api/v1/events', headers: $this->bearer('g0'))));
        foreach ([['ids' => 'x'], ['ids' => ['a']], ['ids' => [1.5]]] as $body) {
            self::assertSame(400, self::send($app, 'POST', '/api/v1/events/read', $body, $this->bearer('u1'))->status);
        }
        self::assertSame(400, self::send($app, 'GET', '/api/v1/events?unread=maybe', headers: $this->bearer('u1'))->status);
    }

    public function testAdminSyncAndStatus(): void
    {
        $app = $this->app();
        self::send($app, 'POST', '/api/v1/wishes', ['source' => 'steam', 'source_id' => '620']);
        self::send($app, 'PUT', '/api/v1/wishes/1/vote', headers: $this->bearer('u1'));

        $status = self::body(self::send($app, 'GET', '/api/v1/admin/status', headers: $this->bearer('a1')));
        self::assertSame([
            'worker_seen_at' => null,
            'sync_pending' => false,
            'last_sync' => null,
            'last_release_check_at' => null,
            'counts' => ['wishes' => 1, 'votes' => 2, 'anonymous_voters' => 1, 'users' => 2],
        ], $status);

        // the bot account does not count as a user
        $this->pdo->exec("INSERT INTO users (id, username, role, first_seen_at, last_seen_at) VALUES (99, 'Bot', 1, 'x', 'x')");
        $botApp = $this->app(['GAMEVAULT_USERNAME' => 'bot', 'GAMEVAULT_PASSWORD' => 'pw']);
        $counts = self::body(self::send($botApp, 'GET', '/api/v1/admin/status', headers: $this->bearer('a1')))['counts'];
        self::assertIsArray($counts);
        self::assertSame(2, $counts['users']);

        $res = self::send($app, 'POST', '/api/v1/admin/sync', headers: $this->bearer('a1'));
        self::assertSame(202, $res->status);
        self::assertSame(['requested_at' => '2026-06-01T12:00:00Z'], self::body($res));
        self::assertTrue(self::body(self::send($app, 'GET', '/api/v1/admin/status', headers: $this->bearer('a1')))['sync_pending']);

        $this->pdo->exec("INSERT INTO jobs_state (key, value, updated_at) VALUES
            ('last_sync', '{\"started_at\":\"2026-06-01T11:00:00Z\",\"finished_at\":\"2026-06-01T11:00:05Z\",\"ok\":false,\"error\":\"gamevault_unavailable\",\"games\":0,\"matched\":0}', 'x'),
            ('worker_seen', '\"2026-06-01T11:59:55Z\"', 'x')");
        $status = self::body(self::send($app, 'GET', '/api/v1/admin/status', headers: $this->bearer('a1')));
        self::assertSame('2026-06-01T11:59:55Z', $status['worker_seen_at']);
        self::assertIsArray($status['last_sync']);
        self::assertSame('gamevault_unavailable', $status['last_sync']['error']);

        foreach ([['POST', '/api/v1/admin/sync'], ['GET', '/api/v1/admin/status']] as [$method, $path]) {
            self::assertSame(401, self::send($app, $method, $path)->status);
            self::assertSame(403, self::send($app, $method, $path, headers: $this->bearer('u1'))->status);
        }
    }
}
