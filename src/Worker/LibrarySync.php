<?php

declare(strict_types=1);

namespace WishVault\Worker;

use WishVault\Clock;
use WishVault\Config;
use WishVault\Db\Database;
use WishVault\Events\Events;
use WishVault\Http\ApiError;
use WishVault\Library\Matcher;
use WishVault\Support\Data;
use WishVault\Support\Title;
use WishVault\SystemClock;
use WishVault\Upstream\GameVault;
use WishVault\Wishes\Wishes;

/**
 * Mirrors the GameVault library into `gv_games` and marks matching wishes
 * available. Only a complete run marks games as removed; wishes of removed
 * games stay available. Rejected wishes are never touched.
 */
final readonly class LibrarySync
{
    public const int PAGE_SIZE = 200;
    public const int MAX_PAGES = 10000;

    /**
     * @param \Closure(): void $progress called regularly during long runs
     */
    public function __construct(
        private Config $config,
        private \PDO $pdo,
        private GameVault $gameVault,
        private Matcher $matcher,
        private Events $events,
        private Clock $clock,
        private \Closure $progress,
    ) {
    }

    /**
     * @return array{games: int, matched: int}
     */
    public function run(): array
    {
        $now = SystemClock::format($this->clock->now());
        $games = $this->fetchLibrary();
        $known = $this->knownGames();

        foreach ($games as $i => $game) {
            if ($game['complete']) {
                continue;
            }
            $previous = $known[$game['id']] ?? null;
            if ($previous !== null && $previous['updated_at'] === $game['updated_at'] && $game['updated_at'] !== null) {
                $games[$i]['provider_ids'] = self::merge($game['provider_ids'], $previous['provider_ids']);
                $games[$i]['steam_app_ids'] = self::merge($game['steam_app_ids'], $previous['steam_app_ids']);
                continue;
            }
            // the list does not include provider_metadata; read the game itself
            try {
                $full = $this->gameVault->game($game['id']);
            } catch (ApiError) {
                $full = null;
                $games[$i]['updated_at'] = $previous['updated_at'] ?? null; // retry next run
            }
            if ($full !== null) {
                $detail = self::parseGame($full);
                if ($detail !== null) {
                    $games[$i]['provider_ids'] = self::merge($game['provider_ids'], $detail['provider_ids']);
                    $games[$i]['steam_app_ids'] = self::merge($game['steam_app_ids'], $detail['steam_app_ids']);
                }
            } elseif ($previous !== null) {
                $games[$i]['provider_ids'] = self::merge($game['provider_ids'], $previous['provider_ids']);
                $games[$i]['steam_app_ids'] = self::merge($game['steam_app_ids'], $previous['steam_app_ids']);
            }
            if ($i % 25 === 0) {
                ($this->progress)();
            }
        }

        $this->store($games, $now);
        return ['games' => count($games), 'matched' => $this->matchWishes($now)];
    }

    /**
     * @param array<array-key, mixed> $game
     * @return array{id: int, title: string, steam_app_ids: list<int>, provider_ids: list<string>, updated_at: ?string, complete: bool}|null
     */
    public static function parseGame(array $game): ?array
    {
        $id = Data::int($game, 'id');
        if ($id === null || Data::str($game, 'deleted_at') !== null) {
            return null;
        }
        $meta = Data::arr($game, 'metadata');
        $title = Data::str($meta, 'title') ?? Data::str($game, 'title') ?? '';
        $urls = [Data::str($meta, 'provider_data_url')];
        foreach (Data::arr($meta, 'url_websites') ?? [] as $site) {
            $urls[] = is_string($site) ? $site : Data::str($site, 'url');
        }
        $providerIds = [];
        $steamIds = [];
        $metaSlug = Data::str($meta, 'provider_slug');
        $metaId = Data::str($meta, 'provider_data_id');
        $providers = Data::rows($game, 'provider_metadata');
        $entries = $metaSlug !== null && $metaId !== null
            ? [...$providers, ['provider_slug' => $metaSlug, 'provider_data_id' => $metaId]]
            : $providers;
        foreach ($entries as $pm) {
            $slug = Data::str($pm, 'provider_slug');
            $dataId = Data::str($pm, 'provider_data_id') ?? (($n = Data::int($pm, 'provider_data_id')) === null ? null : (string) $n);
            $urls[] = Data::str($pm, 'provider_data_url');
            if ($slug === null || $dataId === null || $dataId === '') {
                continue;
            }
            $providerIds[] = $slug . ':' . $dataId;
            if ($slug === 'steam' && preg_match('/^[1-9][0-9]{0,9}$/', $dataId) === 1) {
                $steamIds[] = (int) $dataId;
            }
        }
        foreach ($urls as $url) {
            if ($url !== null && preg_match_all('#store\.steampowered\.com/app/([0-9]{1,10})#i', $url, $m) > 0) {
                foreach ($m[1] as $appId) {
                    $steamIds[] = (int) $appId;
                }
            }
        }
        return [
            'id' => $id,
            'title' => $title,
            'steam_app_ids' => array_values(array_unique(array_filter($steamIds, static fn (int $a): bool => $a > 0))),
            'provider_ids' => array_values(array_unique($providerIds)),
            'updated_at' => Data::str($game, 'updated_at'),
            'complete' => array_key_exists('provider_metadata', $game),
        ];
    }

    /**
     * @return list<array{id: int, title: string, steam_app_ids: list<int>, provider_ids: list<string>, updated_at: ?string, complete: bool}>
     */
    private function fetchLibrary(): array
    {
        $games = [];
        $page = 1;
        do {
            $result = $this->gameVault->gamesPage($page, self::PAGE_SIZE);
            foreach ($result['data'] as $raw) {
                $game = self::parseGame($raw);
                if ($game !== null) {
                    $games[$game['id']] = $game;
                }
            }
            ($this->progress)();
            $page++;
        } while ($result['data'] !== [] && $page <= $result['total_pages'] && $page <= self::MAX_PAGES);
        return array_values($games);
    }

    /**
     * @return array<int, array{updated_at: ?string, steam_app_ids: list<int>, provider_ids: list<string>}>
     */
    private function knownGames(): array
    {
        $out = [];
        foreach (Database::run($this->pdo, 'SELECT id, gv_updated_at, steam_app_ids, provider_ids FROM gv_games')->fetchAll() as $row) {
            $steam = json_decode(Data::str($row, 'steam_app_ids') ?? '[]', true);
            $provider = json_decode(Data::str($row, 'provider_ids') ?? '[]', true);
            $out[(int) Data::int($row, 'id')] = [
                'updated_at' => Data::str($row, 'gv_updated_at'),
                'steam_app_ids' => is_array($steam) ? array_values(array_filter($steam, 'is_int')) : [],
                'provider_ids' => is_array($provider) ? array_values(array_filter($provider, 'is_string')) : [],
            ];
        }
        return $out;
    }

    /**
     * @param list<array{id: int, title: string, steam_app_ids: list<int>, provider_ids: list<string>, updated_at: ?string, complete: bool}> $games
     */
    private function store(array $games, string $now): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach ($games as $game) {
                Database::run(
                    $this->pdo,
                    'INSERT INTO gv_games (id, title, title_normalized, steam_app_ids, provider_ids, gv_updated_at, synced_at, removed_at)
                     VALUES (:id, :title, :norm, :steam, :prov, :upd, :now, NULL)
                     ON CONFLICT(id) DO UPDATE SET title = excluded.title, title_normalized = excluded.title_normalized,
                        steam_app_ids = excluded.steam_app_ids, provider_ids = excluded.provider_ids,
                        gv_updated_at = excluded.gv_updated_at, synced_at = excluded.synced_at, removed_at = NULL',
                    [
                        'id' => $game['id'],
                        'title' => $game['title'],
                        'norm' => Title::normalize($game['title']),
                        'steam' => json_encode($game['steam_app_ids'], JSON_THROW_ON_ERROR),
                        'prov' => json_encode($game['provider_ids'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'upd' => $game['updated_at'],
                        'now' => $now,
                    ],
                );
            }
            Database::run(
                $this->pdo,
                'UPDATE gv_games SET removed_at = :now WHERE removed_at IS NULL AND synced_at <> :now',
                ['now' => $now],
            );
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Returns how many wishes became available. */
    private function matchWishes(string $now): int
    {
        $rows = Database::run(
            $this->pdo,
            "SELECT id, source, source_id, provider_slug, steam_app_id, title_normalized, status, gv_game_id FROM wishes
             WHERE status IN ('new', 'queued', 'paused') OR (status = 'available' AND gv_game_id IS NULL)",
        )->fetchAll();
        $matched = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $gameId = $this->matcher->match(
                Wishes::providerKey($row),
                Data::int($row, 'steam_app_id'),
                Data::str($row, 'title_normalized') ?? '',
            );
            if ($gameId === null) {
                continue;
            }
            $id = (int) Data::int($row, 'id');
            if (Data::str($row, 'status') !== 'available' && $this->config->autoAvailable) {
                $changed = Database::run(
                    $this->pdo,
                    "UPDATE wishes SET status = 'available', gv_game_id = :g, available_at = :now, status_changed_at = :now, updated_at = :now
                     WHERE id = :id AND status IN ('new', 'queued', 'paused')",
                    ['g' => $gameId, 'now' => $now, 'id' => $id],
                )->rowCount();
                if ($changed === 1) {
                    $this->events->notifyInterested($id, 'available');
                    $matched++;
                }
            } elseif (Data::int($row, 'gv_game_id') !== $gameId) {
                Database::run($this->pdo, 'UPDATE wishes SET gv_game_id = :g WHERE id = :id', ['g' => $gameId, 'id' => $id]);
            }
        }
        return $matched;
    }

    /**
     * @template T of int|string
     * @param list<T> $a
     * @param list<T> $b
     * @return list<T>
     */
    private static function merge(array $a, array $b): array
    {
        return array_values(array_unique([...$a, ...$b]));
    }
}
