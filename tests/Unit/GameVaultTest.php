<?php

declare(strict_types=1);

namespace WishVault\Tests\Unit;

use WishVault\Db\Cache;
use WishVault\Http\ApiError;
use WishVault\Tests\TestCase;
use WishVault\Upstream\GameVault;
use WishVault\Upstream\HttpResponse;

final class GameVaultTest extends TestCase
{
    private function gv(bool $bot = true): GameVault
    {
        $env = $bot ? ['GAMEVAULT_USERNAME' => 'bot', 'GAMEVAULT_PASSWORD' => 'pw'] : [];
        return new GameVault(self::config($env), $this->http, new Cache($this->pdo, $this->clock), $this->clock);
    }

    public function testTokenValidationIsCachedUnderAHash(): void
    {
        $this->gvUser('tok', 7, 'alice', 2);
        $gv = $this->gv();
        self::assertSame(['id' => 7, 'username' => 'alice', 'role' => 2], $gv->userForToken('tok'));
        $again = $gv->userForToken('tok');
        self::assertSame(7, $again['id']);
        self::assertSame(1, $this->http->count('#/api/users/me#'));
        $keys = $this->column('SELECT key FROM cache');
        self::assertSame(['gv:me:' . hash('sha256', 'tok')], $keys);
        self::assertFalse($this->http->requests[0]['options']->followRedirects);

        $this->clock->advance('+301 seconds');
        $gv->userForToken('tok');
        self::assertSame(2, $this->http->count('#/api/users/me#'));
    }

    public function testRoleAsStringIsAccepted(): void
    {
        $this->http->json('#/api/users/me$#', ['id' => 3, 'username' => 'x', 'role' => '3']);
        self::assertSame(3, $this->gv()->userForToken('t')['role']);
    }

    public function testRejectedTokenIsUnauthorized(): void
    {
        $this->http->status('#/api/users/me$#', 401);
        try {
            $this->gv()->userForToken('bad');
            self::fail('expected error');
        } catch (ApiError $e) {
            self::assertSame('unauthorized', $e->errorCode);
        }
    }

    public function testDeactivatedUserIsUnauthorized(): void
    {
        $this->http->json('#/api/users/me$#', ['id' => 3, 'username' => 'x', 'role' => 1, 'activated' => false]);
        $this->expectException(ApiError::class);
        $this->gv()->userForToken('t');
    }

    /**
     * @return iterable<string, array{\Closure(\WishVault\Tests\Support\FakeHttp): void}>
     */
    public static function upstreamFailures(): iterable
    {
        yield 'network' => [static fn ($h) => $h->fail('#/api/users/me#')];
        yield '500' => [static fn ($h) => $h->status('#/api/users/me#', 500)];
        yield '429' => [static fn ($h) => $h->status('#/api/users/me#', 429)];
        yield 'redirect' => [static fn ($h) => $h->status('#/api/users/me#', 302)];
        yield 'garbage' => [static fn ($h) => $h->on('#/api/users/me#', new HttpResponse(200, '<html>'))];
        yield 'wrong shape' => [static fn ($h) => $h->json('#/api/users/me#', ['id' => 'x'])];
    }

    /**
     * @param \Closure(\WishVault\Tests\Support\FakeHttp): void $setup
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('upstreamFailures')]
    public function testUpstreamFailuresAreGamevaultUnavailable(\Closure $setup): void
    {
        $setup($this->http);
        try {
            $this->gv()->userForToken('t');
            self::fail('expected error');
        } catch (ApiError $e) {
            self::assertSame(502, $e->status);
            self::assertSame('gamevault_unavailable', $e->errorCode);
        }
    }

    public function testLogin(): void
    {
        $this->gvLogin('alice', 'secret', 'tokA');
        $this->gvUser('tokA', 7, 'alice', 1);
        self::assertSame(7, $this->gv()->login('alice', 'secret')['id']);
    }

    public function testLoginFailures(): void
    {
        foreach ([401, 406] as $status) {
            $this->http->status('#/api/auth/basic/login$#', $status);
            try {
                $this->gv()->login('alice', 'wrong');
                self::fail('expected error');
            } catch (ApiError $e) {
                self::assertSame('login_failed', $e->errorCode);
            }
        }
    }

    public function testActiveProviderIsEnabledWithHighestPriority(): void
    {
        $this->gvLogin('bot', 'pw', 'botTok');
        $this->http->json('#/api/metadata/providers$#', [
            ['slug' => 'igdb', 'name' => 'IGDB', 'priority' => 10, 'enabled' => true],
            ['slug' => 'big', 'name' => 'Big', 'priority' => 50, 'enabled' => false],
            ['slug' => 'other', 'name' => 'Other', 'priority' => 5, 'enabled' => true],
        ]);
        $gv = $this->gv();
        self::assertSame(['slug' => 'igdb', 'name' => 'IGDB'], $gv->activeProvider());
        $gv->activeProvider();
        self::assertSame(1, $this->http->count('#/api/metadata/providers$#'));
        self::assertSame(1, $this->http->count('#/api/auth/basic/login#'));
    }

    public function testBotLogsInAgainOnceAfter401(): void
    {
        $tokens = ['first', 'second'];
        $this->http->on('#/api/auth/basic/login$#', static function () use (&$tokens): HttpResponse {
            return new HttpResponse(200, (string) json_encode(['access_token' => array_shift($tokens)]));
        });
        $this->http->on('#/api/games/9$#', static fn (string $u, array $h): HttpResponse => ($h['Authorization'] ?? '') === 'Bearer second'
            ? new HttpResponse(200, '{"id":9}')
            : new HttpResponse(401, ''));
        self::assertSame(['id' => 9], $this->gv()->game(9));
        self::assertSame(2, $this->http->count('#/api/auth/basic/login#'));
    }

    public function testBotWithoutPermissionIsProviderUnavailable(): void
    {
        $this->gvLogin('bot', 'pw', 'botTok');
        $this->http->status('#/api/metadata/providers/igdb/search#', 403);
        try {
            $this->gv()->providerSearch('igdb', 'x');
            self::fail('expected error');
        } catch (ApiError $e) {
            self::assertSame('provider_unavailable', $e->errorCode);
        }
    }

    public function testWithoutBotCredentialsNothingIsRequested(): void
    {
        try {
            $this->gv(bot: false)->gamesPage(1, 10);
            self::fail('expected error');
        } catch (ApiError $e) {
            self::assertSame('gamevault_unavailable', $e->errorCode);
        }
        self::assertSame([], $this->http->requests);
    }

    public function testProviderSearchParsesResults(): void
    {
        $this->gvLogin('bot', 'pw', 'botTok');
        $this->http->json('#/api/metadata/providers/igdb/search\?query=half\+life$#', [
            ['provider_slug' => 'igdb', 'provider_data_id' => '123', 'title' => 'Half-Life', 'release_date' => '1998-11-19T00:00:00.000Z', 'cover_url' => 'https://img.example.com/c.jpg'],
            ['provider_slug' => 'igdb', 'provider_data_id' => 456, 'title' => 'Half-Life 2'],
            ['provider_slug' => 'igdb', 'title' => 'no id'],
        ]);
        $results = $this->gv()->providerSearch('igdb', 'half life');
        self::assertCount(2, $results);
        self::assertSame('456', $results[1]['provider_data_id']);
        self::assertNull($results[1]['cover_url']);
    }
}
