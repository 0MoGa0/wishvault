<?php

declare(strict_types=1);

namespace WishVault\Tests\Api;

use WishVault\App;
use WishVault\Tests\TestCase;

final class InfoTest extends TestCase
{
    public function testInfo(): void
    {
        $app = new App(self::config(['SITE_NAME' => 'LAN Wishes', 'ANON_ENABLED' => 'false']), self::memoryDb());
        $res = self::call($app, 'GET', '/api/v1/info');
        self::assertSame(200, $res->status);
        $body = self::body($res);
        self::assertSame('wishvault', $body['service']);
        self::assertSame('LAN Wishes', $body['name']);
        self::assertSame('1', $body['api_version']);
        self::assertSame(['anonymous' => false, 'provider_search' => false, 'web_login' => true], $body['features']);
        self::assertNull($body['last_sync_at']);
        self::assertSame('nosniff', $res->headers['X-Content-Type-Options']);
    }

    public function testUnknownRouteIs404(): void
    {
        $res = self::call(new App(self::config(), self::memoryDb()), 'GET', '/api/v1/nope');
        self::assertSame(404, $res->status);
        self::assertSame(['code' => 'not_found', 'message' => 'Not found'], self::body($res)['error']);
    }

    public function testLastSyncAtComesFromTheLastSuccessfulSync(): void
    {
        $pdo = self::memoryDb();
        $pdo->exec("INSERT INTO jobs_state (key, value, updated_at) VALUES ('last_sync_ok_at', '\"2026-06-01T10:00:00Z\"', 'x')");
        $body = self::body(self::call(new App(self::config(), $pdo), 'GET', '/api/v1/info'));
        self::assertSame('2026-06-01T10:00:00Z', $body['last_sync_at']);
    }

    public function testInternalErrorsDoNotLeakDetails(): void
    {
        $pdo = self::memoryDb();
        $pdo->exec('DROP TABLE votes');
        $res = self::call(new App(self::config(), $pdo), 'GET', '/api/v1/wishes');
        self::assertSame(500, $res->status);
        self::assertSame(['code' => 'internal_error', 'message' => 'Internal error'], self::body($res)['error']);
    }

    public function testWrongMethodIs405(): void
    {
        $res = self::call(new App(self::config(), self::memoryDb()), 'DELETE', '/api/v1/info');
        self::assertSame(405, $res->status);
        self::assertSame('GET', $res->headers['Allow']);
    }
}
