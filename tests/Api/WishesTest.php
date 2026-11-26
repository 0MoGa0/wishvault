<?php

declare(strict_types=1);

namespace WishVault\Tests\Api;

use WishVault\App;
use WishVault\Http\Response;
use WishVault\Tests\TestCase;

final class WishesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->standardUsers();
        $this->steamApp(620, 'Portal 2', categories: ['Single-player', 'Online Co-op']);
        $this->steamApp(400, 'Portal');
        $this->steamApp(70, 'Half-Life');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function wish(App $app, string $appId, array $headers = [], array $cookies = []): Response
    {
        return self::send($app, 'POST', '/api/v1/wishes', ['source' => 'steam', 'source_id' => $appId], $headers, $cookies);
    }

    /**
     * @return array<string, mixed>
     */
    private static function wishOf(Response $res): array
    {
        $wish = self::body($res)['wish'];
        self::assertIsArray($wish);
        /** @var array<string, mixed> $wish */
        return $wish;
    }

    public function testCreateFetchesDetailsAndVotes(): void
    {
        $app = $this->app();
        $res = $this->wish($app, '620', $this->bearer('u1'));
        self::assertSame(201, $res->status);
        self::assertTrue(self::body($res)['created']);
        $wish = self::wishOf($res);
        self::assertSame([
            'id' => 1,
            'source' => 'steam',
            'source_id' => '620',
            'title' => 'Portal 2',
            'image_url' => 'https://cdn.example.com/620/header.jpg',
            'release_date' => 'Apr 18, 2011',
            'coming_soon' => false,
            'store_url' => 'https://store.steampowered.com/app/620/',
            'categories' => ['Single-player', 'Online Co-op'],
            'availability' => 'not_available',
            'status' => 'new',
            'reason' => null,
            'note' => null,
            'votes' => 1,
            'anonymous_votes' => null,
            'voted' => true,
            'created_by_me' => true,
            'wished_by' => [['id' => 11, 'username' => 'alice']],
            'gamevault_game_id' => null,
            'created_at' => '2026-06-01T12:00:00Z',
            'updated_at' => '2026-06-01T12:00:00Z',
            'available_at' => null,
        ], $wish);

        // same game again from someone else = +1
        $res = $this->wish($app, '620', $this->bearer('u2'));
        self::assertSame(200, $res->status);
        self::assertFalse(self::body($res)['created']);
        self::assertSame(2, self::wishOf($res)['votes']);
        self::assertFalse(self::wishOf($res)['created_by_me']);
        // and again: idempotent
        self::assertSame(2, self::wishOf($this->wish($app, '620', $this->bearer('u2')))['votes']);
        self::assertSame(1, $this->http->count('#appdetails#'));
    }

    public function testCreateValidation(): void
    {
        $app = $this->app();
        $cases = [
            [['source' => 'steam', 'source_id' => 'abc'], ['source_id' => 'invalid']],
            [['source' => 'steam', 'source_id' => '0'], ['source_id' => 'invalid']],
            [['source' => 'epic', 'source_id' => '1'], ['source' => 'invalid']],
            [['source_id' => '620'], ['source' => 'required']],
            [['source' => 'steam'], ['source_id' => 'required']],
            [['source' => 'provider', 'source_id' => str_repeat('x', 65)], ['source_id' => 'too_long']],
            [['source' => 'provider', 'source_id' => "a b"], ['source_id' => 'invalid']],
        ];
        foreach ($cases as [$body, $details]) {
            $res = self::send($app, 'POST', '/api/v1/wishes', $body, $this->bearer('u1'));
            self::assertSame(400, $res->status, (string) json_encode($body));
            $error = self::body($res)['error'];
            self::assertIsArray($error);
            self::assertSame($details, $error['details']);
        }
    }

    public function testNumericSourceIdIsAccepted(): void
    {
        $res = self::send($this->app(), 'POST', '/api/v1/wishes', ['source' => 'steam', 'source_id' => 620], $this->bearer('u1'));
        self::assertSame(201, $res->status);
    }

    public function testUnknownAndNonGameApps(): void
    {
        $this->steamUnknown(999);
        $this->steamApp(5000, 'Soundtrack', type: 'music');
        $res = $this->wish($this->app(), '999', $this->bearer('u1'));
        self::assertSame(404, $res->status);
        self::assertSame('source_not_found', self::errorCode($res));
        $res = $this->wish($this->app(), '5000', $this->bearer('u1'));
        self::assertSame(400, $res->status);

        $this->http->status('#appdetails\?appids=12&#', 503);
        $res = $this->wish($this->app(), '12', $this->bearer('u1'));
        self::assertSame(502, $res->status);
        self::assertSame('steam_unavailable', self::errorCode($res));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM wishes'));
    }

    public function testAlreadyInLibrary(): void
    {
        $this->pdo->exec("INSERT INTO gv_games (id, title, title_normalized, steam_app_ids, provider_ids, synced_at)
                          VALUES (8, 'Portal 2', 'portal2', '[620]', '[]', 'x'),
                                 (9, 'Half Life', 'halflife', '[]', '[]', 'x')");
        $res = $this->wish($this->app(), '620', $this->bearer('u1'));
        self::assertSame(409, $res->status);
        self::assertSame('already_in_library', self::errorCode($res));
        // title match without contradicting id
        self::assertSame('already_in_library', self::errorCode($this->wish($this->app(), '70', $this->bearer('u1'))));
        // removed games do not count
        $this->pdo->exec("UPDATE gv_games SET removed_at = 'x'");
        self::assertSame(201, $this->wish($this->app(), '620', $this->bearer('u1'))->status);
    }

    public function testPermissions(): void
    {
        $res = $this->wish($this->app(), '620', $this->bearer('g0'));
        self::assertSame(403, $res->status);
        self::assertSame('role_too_low', self::errorCode($res));

        $res = $this->wish($this->app(['ANON_ENABLED' => 'false']), '620');
        self::assertSame(403, $res->status);
        self::assertSame('anonymous_disabled', self::errorCode($res));
        self::assertNull(self::cookie($res, 'wv_anon'));

        $res = $this->wish($this->app(), '620', ['X-Requested-With' => '']);
        self::assertSame('csrf_failed', self::errorCode($res));
    }

    public function testAnonymousWishLimit(): void
    {
        $app = $this->app(['ANON_WISH_LIMIT_PER_HOUR' => '2']);
        $anon = self::cookieValue($this->wish($app, '620'), 'wv_anon');
        self::assertSame(201, $this->wish($app, '400', cookies: ['wv_anon' => $anon])->status);
        $res = $this->wish($app, '70', cookies: ['wv_anon' => $anon]);
        self::assertSame(429, $res->status);
        self::assertSame('rate_limited', self::errorCode($res));
        self::assertSame('3600', $res->headers['Retry-After']);
        // a fresh token from the same IP is limited too
        self::assertSame(429, $this->wish($app, '70')->status);
        // voting on existing wishes still works
        self::assertSame(200, $this->wish($app, '620', cookies: ['wv_anon' => $anon])->status);
        // accounts are not limited
        self::assertSame(201, $this->wish($app, '70', $this->bearer('u1'))->status);
    }

    public function testVisibilityPerCaller(): void
    {
        $app = $this->app();
        $anon = self::cookieValue($this->wish($app, '620'), 'wv_anon');
        $this->wish($app, '620', $this->bearer('u1'));
        self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'queued', 'reason' => 'Next LAN', 'note' => 'buy key'], $this->bearer('a1'));

        $anonView = self::body(self::send($app, 'GET', '/api/v1/wishes/1', cookies: ['wv_anon' => $anon]));
        self::assertNull($anonView['status']);
        self::assertNull($anonView['reason']);
        self::assertNull($anonView['wished_by']);
        self::assertNull($anonView['note']);
        self::assertNull($anonView['anonymous_votes']);
        self::assertSame('not_available', $anonView['availability']);
        self::assertSame(2, $anonView['votes']);
        self::assertTrue($anonView['voted']);
        self::assertTrue($anonView['created_by_me']);

        $userView = self::body(self::send($app, 'GET', '/api/v1/wishes/1', headers: $this->bearer('u1')));
        self::assertSame('queued', $userView['status']);
        self::assertSame('Next LAN', $userView['reason']);
        self::assertSame([['id' => 11, 'username' => 'alice']], $userView['wished_by']);
        self::assertNull($userView['note']);
        self::assertNull($userView['anonymous_votes']);
        self::assertFalse($userView['created_by_me']);

        $guestView = self::body(self::send($app, 'GET', '/api/v1/wishes/1', headers: $this->bearer('g0')));
        self::assertNull($guestView['status']);

        $adminView = self::body(self::send($app, 'GET', '/api/v1/wishes/1', headers: $this->bearer('a1')));
        self::assertSame('buy key', $adminView['note']);
        self::assertSame(1, $adminView['anonymous_votes']);
        self::assertFalse($adminView['voted']);
    }

    public function testListFiltersSortingAndPaging(): void
    {
        $app = $this->app();
        $this->wish($app, '620', $this->bearer('u1'));
        $this->clock->advance('+1 minute');
        $this->wish($app, '400', $this->bearer('u1'));
        $this->wish($app, '400', $this->bearer('u2'));
        $this->clock->advance('+1 minute');
        $this->wish($app, '70', $this->bearer('u1'));
        self::send($app, 'PATCH', '/api/v1/wishes/3', ['status' => 'rejected'], $this->bearer('a1'));

        $ids = static fn (Response $r): array => array_column(self::listItems($r), 'id');
        self::assertSame([2, 1], $ids(self::send($app, 'GET', '/api/v1/wishes')));
        self::assertSame([2, 1], $ids(self::send($app, 'GET', '/api/v1/wishes?filter=open&sort=new')));
        self::assertSame([3, 2, 1], $ids(self::send($app, 'GET', '/api/v1/wishes?filter=all&sort=new', headers: $this->bearer('u1'))));
        self::assertSame([3], $ids(self::send($app, 'GET', '/api/v1/wishes?filter=rejected', headers: $this->bearer('u2'))));
        self::assertSame([], $ids(self::send($app, 'GET', '/api/v1/wishes?filter=available')));

        // votes first, then the most recent vote
        self::assertSame([2, 3, 1], $ids(self::send($app, 'GET', '/api/v1/wishes?filter=all', headers: $this->bearer('u1'))));
        $page = self::send($app, 'GET', '/api/v1/wishes?filter=all&limit=1&offset=1', headers: $this->bearer('u1'));
        self::assertSame([3], $ids($page));
        self::assertSame(3, self::body($page)['total']);

        // anonymous callers never see rejected wishes
        foreach (['rejected', 'all'] as $filter) {
            $res = self::send($app, 'GET', '/api/v1/wishes?filter=' . $filter);
            self::assertSame(403, $res->status);
            self::assertSame('forbidden', self::errorCode($res));
        }
        self::assertSame(404, self::send($app, 'GET', '/api/v1/wishes/3')->status);
        self::assertSame(404, self::send($app, 'GET', '/api/v1/wishes/3', headers: $this->bearer('g0'))->status);

        $res = self::send($app, 'GET', '/api/v1/wishes?filter=x&sort=y&limit=0&offset=-1');
        self::assertSame(400, $res->status);
        $error = self::body($res)['error'];
        self::assertIsArray($error);
        self::assertSame(['filter' => 'invalid', 'sort' => 'invalid', 'limit' => 'invalid', 'offset' => 'invalid'], $error['details']);
        self::assertSame(404, self::send($app, 'GET', '/api/v1/wishes/77')->status);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listItems(Response $res): array
    {
        self::assertSame(200, $res->status, $res->body);
        $items = self::body($res)['items'];
        self::assertIsArray($items);
        /** @var list<array<string, mixed>> $items */
        return $items;
    }

    public function testVoteAndUnvote(): void
    {
        $app = $this->app();
        $this->wish($app, '620', $this->bearer('u1'));

        $res = self::send($app, 'PUT', '/api/v1/wishes/1/vote', headers: $this->bearer('u2'));
        self::assertSame(200, $res->status);
        self::assertSame(2, self::body($res)['votes']);
        self::assertSame(2, self::body(self::send($app, 'PUT', '/api/v1/wishes/1/vote', headers: $this->bearer('u2')))['votes']);
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM votes'));

        $res = self::send($app, 'DELETE', '/api/v1/wishes/1/vote', headers: $this->bearer('u2'));
        self::assertSame(['deleted' => false, 'votes' => 1], ['deleted' => self::body($res)['deleted'], 'votes' => self::wishOf($res)['votes']]);
        // idempotent
        self::assertFalse(self::body(self::send($app, 'DELETE', '/api/v1/wishes/1/vote', headers: $this->bearer('u2')))['deleted']);

        // last vote on a `new` wish deletes it
        $res = self::send($app, 'DELETE', '/api/v1/wishes/1/vote', headers: $this->bearer('u1'));
        self::assertSame(['wish' => null, 'deleted' => true], self::body($res));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM wishes'));
        self::assertSame(404, self::send($app, 'PUT', '/api/v1/wishes/1/vote', headers: $this->bearer('u1'))->status);
    }

    public function testLastVoteOnQueuedWishKeepsIt(): void
    {
        $app = $this->app();
        $this->wish($app, '620', $this->bearer('u1'));
        self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'queued'], $this->bearer('a1'));
        $res = self::send($app, 'DELETE', '/api/v1/wishes/1/vote', headers: $this->bearer('u1'));
        self::assertFalse(self::body($res)['deleted']);
        self::assertSame(0, self::wishOf($res)['votes']);
    }

    public function testAnonymousVoteAndUnvote(): void
    {
        $app = $this->app();
        $this->wish($app, '620', $this->bearer('u1'));
        // unvote without a token does not create one
        $res = self::send($app, 'DELETE', '/api/v1/wishes/1/vote');
        self::assertSame(200, $res->status);
        self::assertNull(self::cookie($res, 'wv_anon'));

        $res = self::send($app, 'PUT', '/api/v1/wishes/1/vote');
        $anon = self::cookieValue($res, 'wv_anon');
        self::assertTrue(self::body($res)['voted']);
        self::assertSame(hash('sha256', $anon), $this->column('SELECT token_hash FROM voters')[0] ?? null);
        $res = self::send($app, 'DELETE', '/api/v1/wishes/1/vote', cookies: ['wv_anon' => $anon]);
        self::assertFalse(self::wishOf($res)['voted']);

        // unknown token cookie is replaced
        $res = self::send($app, 'PUT', '/api/v1/wishes/1/vote', cookies: ['wv_anon' => str_repeat('a', 32)]);
        self::assertNotSame(str_repeat('a', 32), self::cookieValue($res, 'wv_anon'));
    }

    public function testClosedWishesTakeNoVotes(): void
    {
        $app = $this->app();
        $this->wish($app, '620', $this->bearer('u1'));
        $this->wish($app, '400', $this->bearer('u1'));
        self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'available'], $this->bearer('a1'));
        self::send($app, 'PATCH', '/api/v1/wishes/2', ['status' => 'rejected'], $this->bearer('a1'));

        $res = self::send($app, 'PUT', '/api/v1/wishes/1/vote', headers: $this->bearer('u2'));
        self::assertSame(409, $res->status);
        self::assertSame('wish_closed', self::errorCode($res));
        self::assertSame('wish_closed', self::errorCode($this->wish($app, '400', $this->bearer('u2'))));
        // already voted: still fine
        self::assertSame(200, self::send($app, 'PUT', '/api/v1/wishes/1/vote', headers: $this->bearer('u1'))->status);
        // anonymous callers do not learn about rejected wishes
        self::assertSame(404, self::send($app, 'PUT', '/api/v1/wishes/2/vote')->status);
    }

    public function testAdminUpdate(): void
    {
        $app = $this->app();
        $anon = self::cookieValue($this->wish($app, '620'), 'wv_anon');
        $this->wish($app, '620', $this->bearer('u1'));
        $this->wish($app, '620', $this->bearer('u2'));
        $this->clock->advance('+1 hour');

        $res = self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'available', 'reason' => ' Installed ', 'note' => 'n'], $this->bearer('a1'));
        self::assertSame(200, $res->status);
        $wish = self::body($res);
        self::assertSame('available', $wish['status']);
        self::assertSame('available', $wish['availability']);
        self::assertSame('Installed', $wish['reason']);
        self::assertSame('2026-06-01T13:00:00Z', $wish['available_at']);
        self::assertSame('2026-06-01T13:00:00Z', $wish['updated_at']);
        self::assertSame([11, 12], $this->column("SELECT user_id FROM events WHERE type = 'available' ORDER BY user_id"));

        // same status again: no new events
        self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'available'], $this->bearer('a1'));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM events'));

        $wish = self::body(self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'rejected', 'reason' => null], $this->bearer('a1')));
        self::assertNull($wish['available_at']);
        self::assertNull($wish['reason']);
        self::assertSame('n', $wish['note']);
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM events WHERE type = 'rejected'"));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM voters'), $anon);
    }

    public function testAdminUpdateValidationAndRights(): void
    {
        $app = $this->app();
        $this->wish($app, '620', $this->bearer('u1'));
        foreach ([
            [[], ['body' => 'required']],
            [['status' => 'done'], ['status' => 'invalid']],
            [['reason' => str_repeat('x', 501)], ['reason' => 'too_long']],
            [['note' => 5], ['note' => 'invalid']],
            [['votes' => 5], ['votes' => 'invalid']],
        ] as [$body, $details]) {
            $res = self::send($app, 'PATCH', '/api/v1/wishes/1', $body, $this->bearer('a1'));
            self::assertSame(400, $res->status);
            $error = self::body($res)['error'];
            self::assertIsArray($error);
            self::assertSame($details, $error['details']);
        }
        self::assertSame(401, self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'queued'])->status);
        $res = self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'queued'], $this->bearer('u1'));
        self::assertSame(403, $res->status);
        self::assertSame('forbidden', self::errorCode($res));
        self::assertSame(404, self::send($app, 'PATCH', '/api/v1/wishes/9', ['status' => 'queued'], $this->bearer('a1'))->status);
    }

    public function testAdminDelete(): void
    {
        $app = $this->app();
        $this->wish($app, '620', $this->bearer('u1'));
        self::send($app, 'PATCH', '/api/v1/wishes/1', ['status' => 'rejected'], $this->bearer('a1'));
        self::assertSame(403, self::send($app, 'DELETE', '/api/v1/wishes/1', headers: $this->bearer('u1'))->status);
        self::assertSame(204, self::send($app, 'DELETE', '/api/v1/wishes/1', headers: $this->bearer('a1'))->status);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM votes'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM events'));
        self::assertSame(404, self::send($app, 'DELETE', '/api/v1/wishes/1', headers: $this->bearer('a1'))->status);
    }

    public function testTags(): void
    {
        $this->http->json('#GetTagList#', ['response' => ['tags' => [['tagid' => 1, 'name' => 'Puzzle']]]]);
        $this->http->json('#GetItems#', ['response' => ['store_items' => [['tags' => [['tagid' => 1, 'weight' => 5]]]]]]);
        $app = $this->app();
        $this->wish($app, '620', $this->bearer('u1'));
        self::assertSame(['tags' => ['Puzzle']], self::body(self::send($app, 'GET', '/api/v1/wishes/1/tags', headers: $this->bearer('a1'))));
        self::assertSame(403, self::send($app, 'GET', '/api/v1/wishes/1/tags', headers: $this->bearer('u1'))->status);
        self::assertSame(401, self::send($app, 'GET', '/api/v1/wishes/1/tags')->status);

        $this->pdo->exec("UPDATE wishes SET source = 'provider', source_id = 'x1', provider_slug = 'igdb'");
        self::assertSame(['tags' => []], self::body(self::send($app, 'GET', '/api/v1/wishes/1/tags', headers: $this->bearer('a1'))));
    }

    public function testProviderWishFromSearchResult(): void
    {
        $this->gvLogin('bot', 'pw', 'botTok');
        $this->http->json('#/api/metadata/providers$#', [['slug' => 'igdb', 'name' => 'IGDB', 'priority' => 1, 'enabled' => true]]);
        $this->http->json('#/providers/igdb/search\?query=obscure#', [
            ['provider_slug' => 'igdb', 'provider_data_id' => '4242', 'title' => 'Obscure Game', 'release_date' => '2099-01-01T00:00:00.000Z', 'cover_url' => 'https://img.example.com/o.jpg'],
        ]);
        $app = $this->app(['GAMEVAULT_USERNAME' => 'bot', 'GAMEVAULT_PASSWORD' => 'pw']);
        self::send($app, 'GET', '/api/v1/search?q=obscure&source=provider', headers: $this->bearer('u1'));
        $res = self::send($app, 'POST', '/api/v1/wishes', ['source' => 'provider', 'source_id' => '4242'], $this->bearer('u1'));
        self::assertSame(201, $res->status);
        $wish = self::wishOf($res);
        self::assertSame('Obscure Game', $wish['title']);
        self::assertSame('2099-01-01', $wish['release_date']);
        self::assertTrue($wish['coming_soon']);
        self::assertNull($wish['store_url']);
        self::assertSame('igdb', $this->column('SELECT provider_slug FROM wishes')[0] ?? null);

        // not cached and not found by id search
        $this->http->json('#/providers/igdb/search\?query=777#', []);
        $res = self::send($app, 'POST', '/api/v1/wishes', ['source' => 'provider', 'source_id' => '777'], $this->bearer('u1'));
        self::assertSame('source_not_found', self::errorCode($res));

        // provider wishes need the bot
        $res = self::send($this->app(), 'POST', '/api/v1/wishes', ['source' => 'provider', 'source_id' => '777'], $this->bearer('u1'));
        self::assertSame(502, $res->status);
        self::assertSame('provider_unavailable', self::errorCode($res));
    }
}
