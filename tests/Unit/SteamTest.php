<?php

declare(strict_types=1);

namespace WishVault\Tests\Unit;

use WishVault\Db\Cache;
use WishVault\Http\ApiError;
use WishVault\Tests\TestCase;
use WishVault\Upstream\HttpResponse;
use WishVault\Upstream\Steam;

final class SteamTest extends TestCase
{
    private function steam(): Steam
    {
        return new Steam(self::config(['STEAM_COUNTRY' => 'DE', 'STEAM_LANGUAGE' => 'german']), $this->http, new Cache($this->pdo, $this->clock));
    }

    public function testSearchKeepsAppsOnlyAndIsCached(): void
    {
        $items = [['type' => 'sub', 'id' => 1, 'name' => 'Bundle']];
        for ($i = 1; $i <= 12; $i++) {
            $items[] = ['type' => 'app', 'id' => 100 + $i, 'name' => "Game $i", 'tiny_image' => "https://cdn.example.com/$i.jpg"];
        }
        $this->http->json('#/api/storesearch/\?term=portal&l=german&cc=DE$#', ['total' => 13, 'items' => $items]);
        $steam = $this->steam();
        $results = $steam->search('portal');
        self::assertCount(10, $results);
        self::assertSame(['id' => 101, 'name' => 'Game 1', 'image' => 'https://cdn.example.com/1.jpg'], $results[0]);
        self::assertSame($results, $steam->search('Portal '));
        self::assertSame(1, $this->http->count('#storesearch#'));
    }

    public function testAppDetails(): void
    {
        $this->steamApp(620, 'Portal 2', categories: ['Multi-player', 'Co-op', 'Multi-player']);
        $d = $this->steam()->appDetails(620);
        self::assertNotNull($d);
        self::assertSame('game', $d['type']);
        self::assertSame(['Multi-player', 'Co-op'], $d['categories']);
        self::assertSame('Apr 18, 2011', $d['release_date']);
        self::assertStringContainsString('filters=basic%2Crelease_date%2Ccategories', $this->http->requests[0]['url']);
        $this->steam()->appDetails(620);
        self::assertSame(1, $this->http->count('#appdetails#'));
    }

    public function testRegionLockedAppIsRetriedWithoutCountry(): void
    {
        $this->http->on('#appdetails\?appids=77&#', static fn (string $url): HttpResponse => str_contains($url, 'cc=')
            ? new HttpResponse(200, '{"77":{"success":false}}')
            : new HttpResponse(200, '{"77":{"success":true,"data":{"type":"game","name":"Locked"}}}'));
        self::assertSame('Locked', $this->steam()->appDetails(77)['name'] ?? null);
    }

    public function testUnknownAppIsNullAndCachedAsMiss(): void
    {
        $this->steamUnknown(5);
        self::assertNull($this->steam()->appDetails(5));
        self::assertNull($this->steam()->appDetails(5));
        self::assertSame(2, $this->http->count('#appdetails#'));
        $this->clock->advance('+2 hours');
        $this->steam()->appDetails(5);
        self::assertSame(4, $this->http->count('#appdetails#'));
    }

    public function testThrottledAnswersAreSteamUnavailable(): void
    {
        foreach ([new HttpResponse(200, 'null'), new HttpResponse(429, ''), new HttpResponse(503, '')] as $res) {
            $this->http->on('#appdetails#', $res);
            try {
                $this->steam()->appDetails(9, useCache: false);
                self::fail('expected error');
            } catch (ApiError $e) {
                self::assertSame('steam_unavailable', $e->errorCode);
            }
        }
        $this->http->fail('#storesearch#');
        $this->expectException(ApiError::class);
        $this->steam()->search('x');
    }

    public function testTagsSortedByWeight(): void
    {
        $this->http->json('#IStoreService/GetTagList#', ['response' => ['tags' => [
            ['tagid' => 1, 'name' => 'Puzzle'], ['tagid' => 2, 'name' => 'Co-op'], ['tagid' => 3, 'name' => 'Funny'],
        ]]]);
        $this->http->json('#IStoreBrowseService/GetItems#', ['response' => ['store_items' => [['tags' => [
            ['tagid' => 3, 'weight' => 10], ['tagid' => 1, 'weight' => 900], ['tagid' => 2, 'weight' => 300], ['tagid' => 99, 'weight' => 1],
        ]]]]]);
        self::assertSame(['Puzzle', 'Co-op', 'Funny'], $this->steam()->tags(620));
        self::assertCount(3, $this->steam()->tags(620));
        self::assertSame(1, $this->http->count('#GetItems#'));
    }
}
