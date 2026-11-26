<?php

declare(strict_types=1);

namespace WishVault\Tests\Api;

use WishVault\Http\Response;
use WishVault\Tests\TestCase;

final class SearchTest extends TestCase
{
    private const array BOT = ['GAMEVAULT_USERNAME' => 'bot', 'GAMEVAULT_PASSWORD' => 'pw'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->standardUsers();
        $this->gvLogin('bot', 'pw', 'botTok');
        $this->http->json('#/api/metadata/providers$#', [['slug' => 'igdb', 'name' => 'IGDB', 'priority' => 1, 'enabled' => true]]);
        $this->http->json('#storesearch/\?term=portal&#', ['items' => [
            ['type' => 'app', 'id' => 620, 'name' => 'Portal 2', 'tiny_image' => 'https://cdn.example.com/620.jpg'],
            ['type' => 'app', 'id' => 400, 'name' => 'Portal', 'tiny_image' => null],
        ]]);
        $this->http->json('#storesearch/\?term=nothing\+here&#', ['total' => 0, 'items' => []]);
        $this->http->json('#/providers/igdb/search\?query=nothing\+here#', [
            ['provider_slug' => 'igdb', 'provider_data_id' => '77', 'title' => 'Nothing Here', 'release_date' => '2001-05-02T00:00:00.000Z'],
        ]);
        $this->steamApp(620, 'Portal 2');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function results(Response $res): array
    {
        self::assertSame(200, $res->status, $res->body);
        $results = self::body($res)['results'];
        self::assertIsArray($results);
        /** @var list<array<string, mixed>> $results */
        return $results;
    }

    public function testSteamSearchWithMarkers(): void
    {
        $app = $this->app(self::BOT);
        self::send($app, 'POST', '/api/v1/wishes', ['source' => 'steam', 'source_id' => '620'], $this->bearer('u1'));
        $this->pdo->exec("INSERT INTO gv_games (id, title, title_normalized, steam_app_ids, provider_ids, synced_at)
                          VALUES (55, 'Portal', 'portal', '[400]', '[]', 'x')");

        $res = self::send($app, 'GET', '/api/v1/search?q=portal', headers: $this->bearer('u1'));
        $body = self::body($res);
        self::assertSame('steam', $body['source']);
        self::assertFalse($body['fallback']);
        self::assertNull($body['provider']);
        self::assertSame([
            [
                'source' => 'steam', 'source_id' => '620', 'title' => 'Portal 2', 'image_url' => 'https://cdn.example.com/620.jpg',
                'release_date' => null, 'coming_soon' => false, 'store_url' => 'https://store.steampowered.com/app/620/',
                'wish_id' => 1, 'voted' => true, 'gamevault_game_id' => null,
            ],
            [
                'source' => 'steam', 'source_id' => '400', 'title' => 'Portal', 'image_url' => null,
                'release_date' => null, 'coming_soon' => false, 'store_url' => 'https://store.steampowered.com/app/400/',
                'wish_id' => null, 'voted' => false, 'gamevault_game_id' => 55,
            ],
        ], $body['results']);

        $other = self::results(self::send($app, 'GET', '/api/v1/search?q=portal', headers: $this->bearer('u2')));
        self::assertFalse($other[0]['voted']);
        self::assertSame(1, $this->http->count('#storesearch#'));
    }

    public function testRejectedWishIsHiddenFromAnonymousMarkers(): void
    {
        $app = $this->app();
        self::send($app, 'POST', '/api/v1/wishes', ['source' => 'steam', 'source_id' => '620'], $this->bearer('u1'));
        self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'rejected'], $this->bearer('a1'));
        self::assertNull(self::results(self::send($app, 'GET', '/api/v1/search?q=portal'))[0]['wish_id']);
        self::assertSame(1, self::results(self::send($app, 'GET', '/api/v1/search?q=portal', headers: $this->bearer('u2')))[0]['wish_id']);
    }

    public function testAppIdAndLinks(): void
    {
        $app = $this->app(self::BOT);
        foreach (['620', 'https://store.steampowered.com/app/620/Portal_2/'] as $q) {
            $results = self::results(self::send($app, 'GET', '/api/v1/search?q=' . urlencode($q)));
            self::assertCount(1, $results);
            self::assertSame('https://cdn.example.com/620/header.jpg', $results[0]['image_url']);
            self::assertSame('Apr 18, 2011', $results[0]['release_date']);
        }
        self::assertSame(0, $this->http->count('#storesearch#'));

        // DLC or unknown: no result and no provider fallback for ids
        $this->steamApp(1001, 'Some DLC', type: 'dlc');
        $this->steamUnknown(1002);
        foreach (['1001', '1002'] as $q) {
            $body = self::body(self::send($app, 'GET', '/api/v1/search?q=' . $q));
            self::assertSame([], $body['results']);
            self::assertFalse($body['fallback']);
        }
    }

    public function testFallbackToProvider(): void
    {
        $body = self::body(self::send($this->app(self::BOT), 'GET', '/api/v1/search?q=nothing+here'));
        self::assertSame('provider', $body['source']);
        self::assertTrue($body['fallback']);
        self::assertSame(['slug' => 'igdb', 'name' => 'IGDB'], $body['provider']);
        $results = $body['results'];
        self::assertIsArray($results);
        self::assertSame([
            'source' => 'provider', 'source_id' => '77', 'title' => 'Nothing Here', 'image_url' => null,
            'release_date' => '2001-05-02', 'coming_soon' => false, 'store_url' => null,
            'wish_id' => null, 'voted' => false, 'gamevault_game_id' => null,
        ], $results[0]);

        // without bot credentials there is no fallback
        $body = self::body(self::send($this->app(), 'GET', '/api/v1/search?q=nothing+here'));
        self::assertSame('steam', $body['source']);
        self::assertSame([], $body['results']);
    }

    public function testFallbackKeepsEmptySteamResultWhenProviderFails(): void
    {
        // bot without the editor role: GameVault answers 403
        $this->http->json('#storesearch/\?term=locked&#', ['total' => 0, 'items' => []]);
        $this->http->json('#/providers/igdb/search\?query=locked#', ['statusCode' => 403], 403);

        $res = self::send($this->app(self::BOT), 'GET', '/api/v1/search?q=locked');
        self::assertSame(200, $res->status, $res->body);
        $body = self::body($res);
        self::assertSame('steam', $body['source']);
        self::assertFalse($body['fallback']);
        self::assertSame([], $body['results']);

        // the explicit provider search still reports the problem
        $res = self::send($this->app(self::BOT), 'GET', '/api/v1/search?q=locked&source=provider');
        self::assertSame(502, $res->status);
        self::assertSame('provider_unavailable', self::errorCode($res));
    }

    public function testDirectProviderSearchMarksLibraryGames(): void
    {
        $this->http->json('#/providers/igdb/search\?query=portal#', [
            ['provider_slug' => 'igdb', 'provider_data_id' => '71', 'title' => 'Portal'],
        ]);
        $this->pdo->exec("INSERT INTO gv_games (id, title, title_normalized, steam_app_ids, provider_ids, synced_at)
                          VALUES (55, 'Portal (2007)', 'portal2007', '[]', '[\"igdb:71\"]', 'x')");
        $body = self::body(self::send($this->app(self::BOT), 'GET', '/api/v1/search?q=portal&source=provider'));
        self::assertFalse($body['fallback']);
        self::assertSame(55, self::results(self::send($this->app(self::BOT), 'GET', '/api/v1/search?q=portal&source=provider'))[0]['gamevault_game_id']);
    }

    public function testErrors(): void
    {
        $res = self::send($this->app(), 'GET', '/api/v1/search?q=portal&source=provider');
        self::assertSame(502, $res->status);
        self::assertSame('provider_unavailable', self::errorCode($res));

        $res = self::send($this->app(), 'GET', '/api/v1/search?q=a&source=epic');
        self::assertSame(400, $res->status);
        $error = self::body($res)['error'];
        self::assertIsArray($error);
        self::assertSame(['q' => 'too_short', 'source' => 'invalid'], $error['details']);
        self::assertSame(400, self::send($this->app(), 'GET', '/api/v1/search')->status);
        self::assertSame(400, self::send($this->app(), 'GET', '/api/v1/search?q=' . str_repeat('x', 101))->status);

        $this->http->fail('#storesearch/\?term=down#');
        $res = self::send($this->app(), 'GET', '/api/v1/search?q=down');
        self::assertSame(502, $res->status);
        self::assertSame('steam_unavailable', self::errorCode($res));

        self::assertSame(401, self::send($this->app(), 'GET', '/api/v1/search?q=portal', headers: $this->bearer('bad'))->status);
    }

    public function testRateLimit(): void
    {
        $app = $this->app();
        for ($i = 0; $i < 120; $i++) {
            self::send($app, 'GET', '/api/v1/search?q=portal');
        }
        $res = self::send($app, 'GET', '/api/v1/search?q=portal');
        self::assertSame(429, $res->status);
        self::assertArrayHasKey('Retry-After', $res->headers);
        // accounts have their own bucket
        self::assertSame(200, self::send($app, 'GET', '/api/v1/search?q=portal', headers: $this->bearer('u1'))->status);
    }
}
