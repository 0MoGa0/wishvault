<?php

declare(strict_types=1);

namespace WishVault\Tests\Worker;

use WishVault\Config;
use WishVault\Db\Database;
use WishVault\Db\JobsState;
use WishVault\Tests\TestCase;
use WishVault\Upstream\HttpResponse;
use WishVault\Worker\Backup;
use WishVault\Worker\LibrarySync;
use WishVault\Worker\Lock;
use WishVault\Worker\Worker;

final class WorkerTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    /** @var list<string> */
    private array $logs = [];
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir() . '/wishvault-worker-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->gvLogin('bot', 'pw', 'botTok');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataDir . '/backups/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dataDir . '/backups');
        @rmdir($this->dataDir);
        parent::tearDown();
    }

    /**
     * @param array<string, string> $env
     */
    private function cfg(array $env = []): Config
    {
        return self::config($env + ['GAMEVAULT_USERNAME' => 'bot', 'GAMEVAULT_PASSWORD' => 'pw', 'DATA_DIR' => $this->dataDir]);
    }

    /**
     * @param array<string, string> $env
     */
    private function worker(array $env = [], string $owner = 'w1'): Worker
    {
        return new Worker($this->cfg($env), $this->pdo, $this->http, $this->clock, function (int $ms): void {
            $this->sleeps[] = $ms;
        }, $owner, function (string $message): void {
            $this->logs[] = $message;
        });
    }

    /**
     * @param list<list<array<string, mixed>>> $pages
     */
    private function library(array $pages): void
    {
        foreach ($pages as $i => $data) {
            $this->http->json('#/api/games\?page=' . ($i + 1) . '&limit=200&sortBy=id%3AASC$#', [
                'data' => $data,
                'meta' => ['totalPages' => count($pages), 'currentPage' => $i + 1],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function game(int $id, string $title, array $metadata = [], array $extra = []): array
    {
        return $extra + ['id' => $id, 'title' => $title, 'updated_at' => '2026-05-01T00:00:00.000Z', 'provider_metadata' => [], 'metadata' => $metadata + ['title' => $title]];
    }

    private function addWish(string $source, string $sourceId, string $title, string $status = 'new', ?int $steam = null, ?string $slug = null, int $comingSoon = 0): int
    {
        Database::run(
            $this->pdo,
            "INSERT INTO users (id, username, role, first_seen_at, last_seen_at) VALUES (11, 'alice', 1, 'x', 'x') ON CONFLICT DO NOTHING",
        );
        Database::run(
            $this->pdo,
            'INSERT INTO wishes (source, source_id, provider_slug, steam_app_id, title, title_normalized, status, coming_soon, created_by_user, created_at, updated_at)
             VALUES (:s, :i, :slug, :steam, :t, :n, :st, :cs, 11, :now, :now)',
            ['s' => $source, 'i' => $sourceId, 'slug' => $slug, 'steam' => $steam, 't' => $title, 'n' => \WishVault\Support\Title::normalize($title), 'st' => $status, 'cs' => $comingSoon, 'now' => '2026-05-01T00:00:00Z'],
        );
        $id = (int) $this->pdo->lastInsertId();
        Database::run($this->pdo, 'INSERT INTO votes (wish_id, user_id, created_at) VALUES (:w, 11, :t)', ['w' => $id, 't' => 'x']);
        return $id;
    }

    private function wishField(int $id, string $field): mixed
    {
        return Database::run($this->pdo, "SELECT $field FROM wishes WHERE id = :id", ['id' => $id])->fetchColumn();
    }

    public function testPurgeWishesByAge(): void
    {
        // now: 2026-06-01T12:00:00Z
        $oldAvailable = $this->addWish('steam', '1', 'Old Available', 'available');
        $newAvailable = $this->addWish('steam', '2', 'New Available', 'available');
        $oldRejected = $this->addWish('steam', '3', 'Old Rejected', 'rejected');
        $quietOpen = $this->addWish('steam', '4', 'Quiet Open', 'paused');
        $votedOpen = $this->addWish('steam', '5', 'Recently Voted', 'new');
        $quietQueued = $this->addWish('steam', '6', 'Quiet Queued', 'queued');
        $set = fn (int $id, string $sql) => Database::run($this->pdo, "UPDATE wishes SET $sql WHERE id = :id", ['id' => $id]);
        $set($oldAvailable, "available_at = '2026-02-01T00:00:00Z'");
        $set($newAvailable, "available_at = '2026-05-20T00:00:00Z'");
        $set($oldRejected, "status_changed_at = '2026-01-01T00:00:00Z'");
        Database::run($this->pdo, "UPDATE votes SET created_at = '2025-01-01T00:00:00Z'");
        Database::run($this->pdo, "UPDATE wishes SET created_at = '2025-01-01T00:00:00Z' WHERE id IN ($quietOpen, $votedOpen, $quietQueued)");
        Database::run($this->pdo, "UPDATE votes SET created_at = '2026-05-30T00:00:00Z' WHERE wish_id = $votedOpen");
        Database::run($this->pdo, "INSERT INTO events (user_id, type, wish_id, wish_title, created_at) VALUES (11, 'available', $oldAvailable, 'Old Available', 'x')");

        $ids = fn (): array => array_map('intval', Database::run($this->pdo, 'SELECT id FROM wishes ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        $all = [$oldAvailable, $newAvailable, $oldRejected, $quietOpen, $votedOpen, $quietQueued];

        // default: everything off
        self::assertSame(0, $this->worker()->purgeWishes());
        self::assertSame($all, $ids());

        self::assertSame(1, $this->worker(['PURGE_AVAILABLE_DAYS' => '90'])->purgeWishes());
        self::assertNotContains($oldAvailable, $ids());
        self::assertSame(0, (int) Database::run($this->pdo, 'SELECT COUNT(*) FROM events')->fetchColumn());
        self::assertSame(0, (int) Database::run($this->pdo, "SELECT COUNT(*) FROM votes WHERE wish_id = $oldAvailable")->fetchColumn());

        self::assertSame(1, $this->worker(['PURGE_REJECTED_DAYS' => '90'])->purgeWishes());
        self::assertSame(1, $this->worker(['PURGE_OPEN_DAYS' => '365'])->purgeWishes());
        self::assertSame([$newAvailable, $votedOpen, $quietQueued], $ids());
        self::assertSame(1, $this->worker(['PURGE_QUEUED_DAYS' => '365'])->purgeWishes());
        self::assertSame([$newAvailable, $votedOpen], $ids());
        self::assertSame(['purged 1 old wishes', 'purged 1 old wishes', 'purged 1 old wishes', 'purged 1 old wishes'], $this->logs);
    }

    public function testParseGameCollectsAllIds(): void
    {
        $game = LibrarySync::parseGame([
            'id' => 3,
            'title' => 'portal-2-v1.0',
            'updated_at' => 'u',
            'metadata' => [
                'title' => 'Portal 2',
                'provider_slug' => 'gamevault',
                'provider_data_id' => '3',
                'url_websites' => ['https://store.steampowered.com/app/620/Portal_2/', 'https://example.com', ['url' => 'https://store.steampowered.com/app/621']],
                'provider_data_url' => 'https://www.igdb.com/games/portal-2',
            ],
            'provider_metadata' => [
                ['provider_slug' => 'igdb', 'provider_data_id' => '72', 'provider_data_url' => 'https://www.igdb.com/games/portal-2'],
                ['provider_slug' => 'steam', 'provider_data_id' => '620'],
            ],
        ]);
        self::assertNotNull($game);
        self::assertSame('Portal 2', $game['title']);
        self::assertSame([620, 621], $game['steam_app_ids']);
        self::assertSame(['igdb:72', 'steam:620', 'gamevault:3'], $game['provider_ids']);
        self::assertTrue($game['complete']);
        self::assertNull(LibrarySync::parseGame(['id' => 4, 'title' => 'x', 'deleted_at' => '2026-01-01']));
        self::assertNull(LibrarySync::parseGame(['title' => 'x']));
    }

    public function testSyncMatchesInOrderAndCreatesEvents(): void
    {
        $byProvider = $this->addWish('provider', '72', 'Some Other Name', slug: 'igdb');
        $bySteam = $this->addWish('steam', '400', 'Portal Classic', steam: 400);
        $byTitle = $this->addWish('steam', '70', 'Half-Life', steam: 70);
        $conflict = $this->addWish('steam', '220', 'Half-Life 2', steam: 220);
        $rejected = $this->addWish('steam', '500', 'Left 4 Dead', 'rejected', steam: 500);
        $queued = $this->addWish('steam', '550', 'Left 4 Dead 2', 'queued', steam: 550);
        $none = $this->addWish('steam', '999', 'Not There', steam: 999);

        $this->library([
            [
                self::game(1, 'Portal 2', [], ['provider_metadata' => [['provider_slug' => 'igdb', 'provider_data_id' => '72']]]),
                self::game(2, 'Portal', ['url_websites' => ['https://store.steampowered.com/app/400/']]),
            ],
            [
                self::game(3, 'Half Life'),
                self::game(4, 'Half-Life 2', ['url_websites' => ['https://store.steampowered.com/app/9220/']]),
                self::game(5, 'Left 4 Dead', ['url_websites' => ['https://store.steampowered.com/app/500/']]),
                self::game(6, 'Left 4 Dead 2', ['url_websites' => ['https://store.steampowered.com/app/550/']]),
            ],
        ]);

        $this->http->json('#/api/metadata/providers$#', [['slug' => 'igdb', 'name' => 'IGDB', 'priority' => 10, 'enabled' => true]]);
        $this->worker()->sync();

        self::assertSame(['slug' => 'igdb', 'name' => 'IGDB'], (new JobsState($this->pdo, $this->clock))->get('provider'));
        $state = (new JobsState($this->pdo, $this->clock))->get('last_sync');
        self::assertIsArray($state);
        self::assertTrue($state['ok']);
        self::assertSame(6, $state['games']);
        self::assertSame(4, $state['matched']);
        self::assertSame('2026-06-01T12:00:00Z', $state['finished_at']);

        self::assertSame(1, $this->wishField($byProvider, 'gv_game_id'));
        self::assertSame(2, $this->wishField($bySteam, 'gv_game_id'));
        self::assertSame(3, $this->wishField($byTitle, 'gv_game_id'));
        self::assertSame('available', $this->wishField($byTitle, 'status'));
        self::assertSame('2026-06-01T12:00:00Z', $this->wishField($byTitle, 'available_at'));
        self::assertSame('new', $this->wishField($conflict, 'status'), 'title match with a different AppID');
        self::assertSame('rejected', $this->wishField($rejected, 'status'));
        self::assertNull($this->wishField($rejected, 'gv_game_id'));
        self::assertSame('available', $this->wishField($queued, 'status'));
        self::assertSame('new', $this->wishField($none, 'status'));
        self::assertSame(4, $this->scalar("SELECT COUNT(*) FROM events WHERE type = 'available' AND user_id = 11"));
        self::assertSame(2, $this->http->count('#/api/games\?#'));
        self::assertSame(1, $this->http->count('#/api/auth/basic/login#'), 'bot token reused within the process');

        $info = self::body(self::send($this->app(), 'GET', '/api/v1/info'));
        self::assertSame('2026-06-01T12:00:00Z', $info['last_sync_at']);
    }

    public function testRemovedGamesKeepWishesAvailable(): void
    {
        $wish = $this->addWish('steam', '400', 'Portal', steam: 400);
        $this->library([[self::game(2, 'Portal', ['url_websites' => ['https://store.steampowered.com/app/400/']])]]);
        $this->worker()->sync();
        self::assertSame('available', $this->wishField($wish, 'status'));

        $this->clock->advance('+1 hour');
        $this->library([[]]);
        $this->worker()->sync();
        self::assertSame('2026-06-01T13:00:00Z', Database::run($this->pdo, 'SELECT removed_at FROM gv_games WHERE id = 2')->fetchColumn());
        self::assertSame('available', $this->wishField($wish, 'status'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM events'));

        // back again
        $this->clock->advance('+1 hour');
        $this->library([[self::game(2, 'Portal')]]);
        $this->worker()->sync();
        self::assertNull(Database::run($this->pdo, 'SELECT removed_at FROM gv_games WHERE id = 2')->fetchColumn());
    }

    public function testFailedPageAbortsWithoutMarkingRemovals(): void
    {
        $this->library([[self::game(1, 'A')], [self::game(2, 'B')]]);
        $this->worker()->sync();
        $this->clock->advance('+1 hour');
        $this->http->status('#/api/games\?page=2&#', 500);
        $this->worker()->sync();
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM gv_games WHERE removed_at IS NOT NULL'));
        $state = (new JobsState($this->pdo, $this->clock))->get('last_sync');
        self::assertIsArray($state);
        self::assertFalse($state['ok']);
        self::assertSame('gamevault_unavailable', $state['error']);
        self::assertSame(['provider lookup failed: gamevault_unavailable', 'sync failed: gamevault_unavailable'], $this->logs);
        $info = self::body(self::send($this->app(), 'GET', '/api/v1/info'));
        self::assertSame('2026-06-01T12:00:00Z', $info['last_sync_at'], 'last successful sync');
    }

    public function testProviderIdsFromGameDetailsWhenTheListLacksThem(): void
    {
        $wish = $this->addWish('provider', '72', 'Different', slug: 'igdb');
        $listGame = ['id' => 1, 'title' => 'P2', 'updated_at' => 'v1', 'metadata' => ['title' => 'P2']];
        $this->library([[$listGame]]);
        $this->http->json('#/api/games/1$#', $listGame + ['provider_metadata' => [['provider_slug' => 'igdb', 'provider_data_id' => '72']]]);
        $this->worker(['AUTO_AVAILABLE' => 'false'])->sync();
        self::assertSame(1, $this->http->count('#/api/games/1$#'));
        self::assertSame('["igdb:72"]', Database::run($this->pdo, 'SELECT provider_ids FROM gv_games')->fetchColumn());
        // AUTO_AVAILABLE off: only the game id is remembered
        self::assertSame('new', $this->wishField($wish, 'status'));
        self::assertSame(1, $this->wishField($wish, 'gv_game_id'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM events'));

        // unchanged game: no second detail request, ids kept
        $this->worker()->sync();
        self::assertSame(1, $this->http->count('#/api/games/1$#'));
        self::assertSame('available', $this->wishField($wish, 'status'));
    }

    public function testTickSchedulesJobsAndHonoursTheSyncFlag(): void
    {
        $this->library([[]]);
        $this->http->json('#appdetails#', []);
        $worker = $this->worker();
        self::assertSame(['sync', 'release_check', 'purge', 'backup'], $worker->tick());
        self::assertSame([], $worker->tick());
        self::assertFileExists($this->dataDir . '/backups/wishvault-2026-06-01.sqlite');

        $state = new JobsState($this->pdo, $this->clock);
        self::assertSame('2026-06-01T12:00:00Z', $state->get('worker_seen'));

        $state->set('sync_requested', '2026-06-01T12:00:05Z');
        $this->clock->advance('+10 seconds');
        self::assertSame(['sync'], $worker->tick());
        self::assertNull($state->get('sync_requested'));

        $this->clock->advance('+900 seconds');
        self::assertSame(['sync'], $worker->tick());
        $this->clock->advance('+1 hour');
        self::assertSame(['sync', 'purge'], $worker->tick());
        $this->clock->advance('+1 day');
        self::assertSame(['sync', 'release_check', 'purge', 'backup'], $worker->tick());
    }

    public function testSecondWorkerWaitsForTheLock(): void
    {
        $this->library([[]]);
        self::assertNotSame([], $this->worker(owner: 'a')->tick());
        $state = new JobsState($this->pdo, $this->clock);
        $state->set('sync_requested', 'now');
        self::assertSame([], $this->worker(owner: 'b')->tick());
        self::assertNotNull($state->get('sync_requested'));
        $this->clock->advance('+601 seconds');
        self::assertContains('sync', $this->worker(owner: 'b')->tick());

        $lock = new Lock($this->pdo, $this->clock);
        $lock->release('b');
        self::assertTrue($lock->acquire('c', 60));
    }

    public function testReleaseCheck(): void
    {
        $soon = $this->addWish('steam', '620', 'Portal 2', steam: 620, comingSoon: 1);
        $still = $this->addWish('steam', '621', 'Portal 3', steam: 621, comingSoon: 1);
        $rejected = $this->addWish('steam', '622', 'Portal 4', 'rejected', steam: 622, comingSoon: 1);
        $this->steamApp(620, 'Portal 2', comingSoon: false);
        $this->steamApp(621, 'Portal 3', comingSoon: true);
        $this->steamApp(622, 'Portal 4', comingSoon: false);

        $worker = $this->worker();
        $worker->releaseCheck();
        self::assertSame(0, $this->wishField($soon, 'coming_soon'));
        self::assertSame('Apr 18, 2011', $this->wishField($soon, 'release_date'));
        self::assertSame(1, $this->wishField($still, 'coming_soon'));
        self::assertSame('Q3 2027', $this->wishField($still, 'release_date'));
        self::assertSame(1, $this->wishField($rejected, 'coming_soon'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM events WHERE type = 'released'"));
        self::assertSame([300], $this->sleeps);
        self::assertSame(2, $this->http->count('#appdetails#'));

        // checked recently: nothing to do; after STEAM_RECHECK_HOURS again, without cache
        $worker->releaseCheck();
        self::assertSame(2, $this->http->count('#appdetails#'));
        $this->clock->advance('+25 hours');
        $worker->releaseCheck();
        self::assertSame(3, $this->http->count('#appdetails#'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM events WHERE type = 'released'"));
    }

    public function testReleaseCheckStopsWhenSteamThrottles(): void
    {
        $this->addWish('steam', '620', 'A', steam: 620, comingSoon: 1);
        $this->addWish('steam', '621', 'B', steam: 621, comingSoon: 1);
        $this->http->on('#appdetails#', new HttpResponse(429, ''));
        $this->worker()->releaseCheck();
        self::assertSame(1, $this->http->count('#appdetails#'));
        self::assertNotNull((new JobsState($this->pdo, $this->clock))->get('last_release_check'));
    }

    public function testPurge(): void
    {
        $this->pdo->exec("INSERT INTO cache (key, value, expires_at) VALUES ('old', '1', 1), ('new', '1', 99999999999)");
        $this->pdo->exec("INSERT INTO users (id, username, role, first_seen_at, last_seen_at) VALUES (1, 'a', 1, 'x', 'x')");
        $this->pdo->exec("INSERT INTO sessions (token_hash, user_id, created_at, expires_at, last_seen_at) VALUES
            ('a', 1, 'x', '2026-01-01T00:00:00Z', 'x'), ('b', 1, 'x', '2027-01-01T00:00:00Z', 'x')");
        $this->pdo->exec("INSERT INTO voters (token_hash, created_at, last_seen_at) VALUES ('old', 'x', '2026-01-01T00:00:00Z'), ('recent', 'x', '2026-05-30T00:00:00Z')");
        $this->pdo->exec('INSERT INTO rate_hits (bucket, hit_at) VALUES (\'x\', 1)');
        $this->worker()->purge();
        self::assertSame(['new'], $this->column('SELECT key FROM cache'));
        self::assertSame(['b'], $this->column('SELECT token_hash FROM sessions'));
        self::assertSame(['recent'], $this->column('SELECT token_hash FROM voters'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM rate_hits'));
    }

    public function testBackupKeepsSeven(): void
    {
        $dir = $this->dataDir . '/backups';
        $backup = new Backup($this->pdo, $dir, $this->clock);
        for ($i = 0; $i < 9; $i++) {
            $file = $backup->run();
            $this->clock->advance('+1 day');
        }
        $files = array_map('basename', glob($dir . '/*.sqlite') ?: []);
        self::assertCount(7, $files);
        self::assertSame('wishvault-2026-06-03.sqlite', $files[0]);
        self::assertSame('wishvault-2026-06-09.sqlite', basename($file));
        $copy = new \PDO('sqlite:' . $file);
        self::assertSame(1, Database::run($copy, 'PRAGMA user_version')->fetchColumn());
    }
}
