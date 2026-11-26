<?php

declare(strict_types=1);

namespace WishVault\Wishes;

use WishVault\Auth\Caller;
use WishVault\Clock;
use WishVault\Config;
use WishVault\Db\Cache;
use WishVault\Db\Database;
use WishVault\Events\Events;
use WishVault\Http\ApiError;
use WishVault\Library\Matcher;
use WishVault\Security\RateLimiter;
use WishVault\Support\Data;
use WishVault\Support\Title;
use WishVault\SystemClock;
use WishVault\Upstream\GameVault;
use WishVault\Upstream\Steam;

/**
 * Wishes and votes. Vote counts always come from COUNT(*) over `votes`;
 * uniqueness is enforced by the database (wishes per source id, votes per
 * person), so concurrent requests cannot create duplicates.
 *
 * @phpstan-type WishRow array<string, mixed>
 */
final readonly class Wishes
{
    public const array STATUSES = ['new', 'queued', 'paused', 'rejected', 'available'];
    public const array OPEN = ['new', 'queued', 'paused'];
    public const array CLOSED = ['rejected', 'available'];
    public const int PROVIDER_ITEM_TTL = 86400;

    public function __construct(
        private Config $config,
        private \PDO $pdo,
        private Steam $steam,
        private GameVault $gameVault,
        private Cache $cache,
        private Matcher $matcher,
        private Events $events,
        private RateLimiter $limiter,
        private Clock $clock,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(Caller $caller, string $filter, string $sort, int $limit, int $offset): array
    {
        if (in_array($filter, ['rejected', 'all'], true) && !$caller->seesDetails()) {
            throw ApiError::forbidden('forbidden', 'This filter needs an account');
        }
        $where = match ($filter) {
            'open' => "w.status IN ('new', 'queued', 'paused')",
            'available' => "w.status = 'available'",
            'rejected' => "w.status = 'rejected'",
            default => '1 = 1',
        };
        $order = $sort === 'new'
            ? 'w.created_at DESC, w.id DESC'
            : 'vote_count DESC, last_vote_at DESC, w.id DESC';
        $total = (int) Data::int(Database::run($this->pdo, "SELECT COUNT(*) FROM wishes w WHERE $where")->fetchColumn());
        $rows = $this->select($caller, $where, [], "ORDER BY $order LIMIT :limit OFFSET :offset", [
            'limit' => $limit,
            'offset' => $offset,
        ]);
        return ['items' => $this->present($rows, $caller), 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Caller $caller, int $id): array
    {
        return $this->present([$this->visibleRow($caller, $id)], $caller)[0];
    }

    /**
     * New wish with the caller's vote, or +1 on the existing wish.
     *
     * @return array{wish: array<string, mixed>, created: bool}
     */
    public function create(Caller $caller, string $clientIp, string $source, string $sourceId): array
    {
        $steamAppId = null;
        if ($source === 'steam') {
            $steamAppId = Steam::validAppId($sourceId) ?? throw ApiError::validation(['source_id' => 'invalid']);
            $sourceId = (string) $steamAppId;
        } elseif (preg_match('/^[\x21-\x7E]{1,64}$/', $sourceId) !== 1) {
            throw ApiError::validation(['source_id' => 'invalid']);
        }

        $existing = $this->idBySource($source, $sourceId);
        if ($existing !== null) {
            return ['wish' => $this->vote($caller, $existing), 'created' => false];
        }

        if (!$caller->isAccount()) {
            $this->limitAnonymousWish($caller, $clientIp);
        }

        $wish = $source === 'steam'
            ? $this->steamWish((int) $steamAppId)
            : $this->providerWish($sourceId);

        $gameId = $this->matcher->match($wish['provider_key'], $wish['steam_app_id'], $wish['title_normalized']);
        if ($gameId !== null) {
            throw ApiError::conflict('already_in_library', 'The game is already in GameVault');
        }

        $now = $this->now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $inserted = Database::run(
                $this->pdo,
                'INSERT INTO wishes (source, source_id, provider_slug, steam_app_id, title, title_normalized, image_url,
                    release_date, coming_soon, store_url, categories, status, created_by_user, created_by_voter,
                    created_at, updated_at, details_checked_at)
                 VALUES (:source, :source_id, :slug, :steam, :title, :norm, :image, :release, :soon, :store, :cats,
                    \'new\', :user, :voter, :now, :now, :now)
                 ON CONFLICT(source, source_id) DO NOTHING',
                [
                    'source' => $source,
                    'source_id' => $sourceId,
                    'slug' => $wish['provider_slug'],
                    'steam' => $wish['steam_app_id'],
                    'title' => $wish['title'],
                    'norm' => $wish['title_normalized'],
                    'image' => $wish['image_url'],
                    'release' => $wish['release_date'],
                    'soon' => $wish['coming_soon'] ? 1 : 0,
                    'store' => $wish['store_url'],
                    'cats' => json_encode($wish['categories'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'user' => $caller->userId,
                    'voter' => $caller->userId === null ? $caller->voterId : null,
                    'now' => $now,
                ],
            )->rowCount();
            $id = $inserted === 1 ? (int) $this->pdo->lastInsertId() : null;
            if ($id !== null) {
                $this->insertVote($caller, $id);
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        if ($id === null) {
            // created concurrently by someone else
            $existing = $this->idBySource($source, $sourceId) ?? throw ApiError::notFound();
            return ['wish' => $this->vote($caller, $existing), 'created' => false];
        }
        if (!$caller->isAccount()) {
            $this->limiter->hit('wish:v' . $caller->voterId);
            $this->limiter->hit('wish:ip:' . $clientIp);
        }
        return ['wish' => $this->get($caller, $id), 'created' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function vote(Caller $caller, int $id): array
    {
        $row = $this->visibleRow($caller, $id);
        if (in_array($row['status'], self::CLOSED, true) && !$this->hasVoted($caller, $id)) {
            throw ApiError::conflict('wish_closed', 'The wish does not take votes anymore');
        }
        $this->insertVote($caller, $id);
        return $this->get($caller, $id);
    }

    /**
     * @return array{wish: array<string, mixed>|null, deleted: bool}
     */
    public function unvote(Caller $caller, int $id): array
    {
        $this->visibleRow($caller, $id);
        $deleted = false;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($caller->userId !== null) {
                Database::run($this->pdo, 'DELETE FROM votes WHERE wish_id = :w AND user_id = :u', ['w' => $id, 'u' => $caller->userId]);
            } elseif ($caller->voterId !== null) {
                Database::run($this->pdo, 'DELETE FROM votes WHERE wish_id = :w AND voter_id = :v', ['w' => $id, 'v' => $caller->voterId]);
            }
            $deleted = Database::run(
                $this->pdo,
                "DELETE FROM wishes WHERE id = :w AND status = 'new'
                 AND NOT EXISTS (SELECT 1 FROM votes WHERE wish_id = :w)",
                ['w' => $id],
            )->rowCount() === 1;
            if ($deleted) {
                Database::run($this->pdo, 'DELETE FROM events WHERE wish_id = :w', ['w' => $id]);
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
        return $deleted
            ? ['wish' => null, 'deleted' => true]
            : ['wish' => $this->get($caller, $id), 'deleted' => false];
    }

    /**
     * Admin: status, reason, note.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(Caller $caller, int $id, array $body): array
    {
        $changes = self::validateUpdate($body);
        $row = $this->row($id) ?? throw ApiError::notFound();
        $oldStatus = (string) Data::str($row, 'status');
        $now = $this->now();
        $set = ['updated_at = :now'];
        $params = ['id' => $id, 'now' => $now];
        foreach (['reason', 'note'] as $field) {
            if (array_key_exists($field, $changes)) {
                $set[] = "$field = :$field";
                $params[$field] = $changes[$field];
            }
        }
        $newStatus = $changes['status'] ?? $oldStatus;
        if ($newStatus !== $oldStatus) {
            $set[] = 'status = :status';
            $params['status'] = $newStatus;
            if ($newStatus === 'available') {
                $set[] = 'available_at = :now';
                if (Data::int($row, 'gv_game_id') === null) {
                    $gameId = $this->matcher->match(
                        self::providerKey($row),
                        Data::int($row, 'steam_app_id'),
                        (string) Data::str($row, 'title_normalized'),
                    );
                    if ($gameId !== null) {
                        $set[] = 'gv_game_id = :game';
                        $params['game'] = $gameId;
                    }
                }
            } elseif ($oldStatus === 'available') {
                $set[] = 'available_at = NULL';
            }
        }
        Database::run($this->pdo, 'UPDATE wishes SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
        if ($newStatus !== $oldStatus && in_array($newStatus, ['available', 'rejected'], true)) {
            $this->events->notifyInterested($id, $newStatus);
        }
        return $this->get($caller, $id);
    }

    public function delete(int $id): void
    {
        $this->row($id) ?? throw ApiError::notFound();
        Database::run($this->pdo, 'DELETE FROM events WHERE wish_id = :id', ['id' => $id]);
        Database::run($this->pdo, 'DELETE FROM wishes WHERE id = :id', ['id' => $id]);
    }

    /**
     * Steam user tags (admin).
     *
     * @return list<string>
     */
    public function tags(int $id): array
    {
        $row = $this->row($id) ?? throw ApiError::notFound();
        $appId = Data::str($row, 'source') === 'steam'
            ? Steam::validAppId((string) Data::str($row, 'source_id'))
            : null;
        return $appId === null ? [] : $this->steam->tags($appId);
    }

    /**
     * Markers for search results: existing wish, own vote, game in GameVault.
     *
     * @param array{source: string, source_id: string, steam_app_id: ?int, provider_key: ?string, title: string} $item
     * @return array{wish_id: ?int, voted: bool, gamevault_game_id: ?int}
     */
    public function markers(Caller $caller, array $item): array
    {
        $rows = $this->select(
            $caller,
            'w.source = :source AND w.source_id = :source_id',
            ['source' => $item['source'], 'source_id' => $item['source_id']],
        );
        $row = $rows[0] ?? null;
        if ($row !== null && $row['status'] === 'rejected' && !$caller->seesDetails()) {
            $row = null;
        }
        return [
            'wish_id' => $row === null ? null : Data::int($row, 'id'),
            'voted' => $row !== null && (bool) $row['voted'],
            'gamevault_game_id' => $this->matcher->match(
                $item['provider_key'],
                $item['steam_app_id'],
                Title::normalize($item['title']),
            ),
        ];
    }

    /**
     * Remembers a provider search result so a wish can be created from it.
     *
     * @param array{provider_slug: string, provider_data_id: string, title: string, release_date: ?string, cover_url: ?string} $item
     */
    public function rememberProviderItem(array $item): void
    {
        $this->cache->set(
            'provider:item:' . $item['provider_slug'] . ':' . $item['provider_data_id'],
            $item,
            self::PROVIDER_ITEM_TTL,
        );
    }

    /** Provider release dates come as ISO date-time; keep the date. */
    public static function providerDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})/', $value, $m) === 1 ? $m[1] : $value;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function providerKey(array $row): ?string
    {
        $slug = Data::str($row, 'provider_slug');
        return Data::str($row, 'source') === 'provider' && $slug !== null
            ? $slug . ':' . Data::str($row, 'source_id')
            : null;
    }

    private function limitAnonymousWish(Caller $caller, string $clientIp): void
    {
        $limit = $this->config->anonWishLimitPerHour;
        $this->limiter->check('wish:v' . $caller->voterId, $limit, 3600);
        $this->limiter->check('wish:ip:' . $clientIp, $limit, 3600);
        // every lookup costs an upstream request, also failed ones
        $this->limiter->attempt('lookup:ip:' . $clientIp, max(20, 3 * $limit), 3600);
    }

    /**
     * @return array{title: string, title_normalized: string, image_url: ?string, release_date: ?string, coming_soon: bool, store_url: ?string, categories: list<string>, steam_app_id: ?int, provider_slug: ?string, provider_key: ?string}
     */
    private function steamWish(int $appId): array
    {
        $details = $this->steam->appDetails($appId) ?? throw ApiError::notFound('source_not_found', 'Unknown Steam app');
        if ($details['type'] !== 'game') {
            throw ApiError::validation(['source_id' => 'invalid']);
        }
        return [
            'title' => $details['name'],
            'title_normalized' => Title::normalize($details['name']),
            'image_url' => $details['header_image'],
            'release_date' => $details['release_date'],
            'coming_soon' => $details['coming_soon'],
            'store_url' => Steam::storeUrl($appId),
            'categories' => $details['categories'],
            'steam_app_id' => $appId,
            'provider_slug' => null,
            'provider_key' => null,
        ];
    }

    /**
     * @return array{title: string, title_normalized: string, image_url: ?string, release_date: ?string, coming_soon: bool, store_url: ?string, categories: list<string>, steam_app_id: ?int, provider_slug: ?string, provider_key: ?string}
     */
    private function providerWish(string $dataId): array
    {
        if (!$this->config->providerSearchEnabled()) {
            throw ApiError::upstream('provider_unavailable', 'Provider search is not configured');
        }
        $provider = $this->gameVault->activeProvider()
            ?? throw ApiError::upstream('provider_unavailable', 'No active metadata provider');
        $slug = $provider['slug'];
        $item = null;
        $cached = $this->cache->get('provider:item:' . $slug . ':' . $dataId);
        if (is_array($cached) && Data::str($cached, 'title') !== null) {
            $item = ['title' => (string) Data::str($cached, 'title'), 'release_date' => Data::str($cached, 'release_date'), 'cover_url' => Data::str($cached, 'cover_url')];
        } else {
            foreach ($this->gameVault->providerSearch($slug, $dataId) as $result) {
                if ($result['provider_data_id'] === $dataId) {
                    $item = $result;
                    break;
                }
            }
        }
        if ($item === null) {
            throw ApiError::notFound('source_not_found', 'Unknown provider game');
        }
        $date = self::providerDate($item['release_date']);
        $today = $this->clock->now()->format('Y-m-d');
        return [
            'title' => $item['title'],
            'title_normalized' => Title::normalize($item['title']),
            'image_url' => $item['cover_url'],
            'release_date' => $date,
            'coming_soon' => $date !== null && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date) === 1 && $date > $today,
            'store_url' => null,
            'categories' => [],
            'steam_app_id' => null,
            'provider_slug' => $slug,
            'provider_key' => $slug . ':' . $dataId,
        ];
    }

    private function insertVote(Caller $caller, int $wishId): void
    {
        if ($caller->userId !== null) {
            Database::run(
                $this->pdo,
                'INSERT OR IGNORE INTO votes (wish_id, user_id, created_at) VALUES (:w, :u, :t)',
                ['w' => $wishId, 'u' => $caller->userId, 't' => $this->now()],
            );
        } elseif ($caller->voterId !== null) {
            Database::run(
                $this->pdo,
                'INSERT OR IGNORE INTO votes (wish_id, voter_id, created_at) VALUES (:w, :v, :t)',
                ['w' => $wishId, 'v' => $caller->voterId, 't' => $this->now()],
            );
        } else {
            throw new \LogicException('A vote needs a user or a voter');
        }
    }

    private function hasVoted(Caller $caller, int $wishId): bool
    {
        return Database::run(
            $this->pdo,
            'SELECT 1 FROM votes WHERE wish_id = :w AND (user_id = :u OR voter_id = :v)',
            ['w' => $wishId, 'u' => $caller->userId, 'v' => $caller->userId === null ? $caller->voterId : null],
        )->fetchColumn() !== false;
    }

    private function idBySource(string $source, string $sourceId): ?int
    {
        return Data::int(Database::run(
            $this->pdo,
            'SELECT id FROM wishes WHERE source = :s AND source_id = :i',
            ['s' => $source, 'i' => $sourceId],
        )->fetchColumn());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(int $id): ?array
    {
        $row = Database::run($this->pdo, 'SELECT * FROM wishes WHERE id = :id', ['id' => $id])->fetch();
        /** @var array<string, mixed>|false $row */
        return $row === false ? null : $row;
    }

    /**
     * Row incl. counts; rejected wishes do not exist for callers without details.
     *
     * @return array<string, mixed>
     */
    private function visibleRow(Caller $caller, int $id): array
    {
        $row = $this->select($caller, 'w.id = :id', ['id' => $id])[0] ?? null;
        if ($row === null || ($row['status'] === 'rejected' && !$caller->seesDetails())) {
            throw ApiError::notFound();
        }
        return $row;
    }

    /**
     * @param array<string, scalar|null> $params
     * @param array<string, scalar|null> $tailParams
     * @return list<array<string, mixed>>
     */
    private function select(Caller $caller, string $where, array $params, string $tail = '', array $tailParams = []): array
    {
        $rows = Database::run(
            $this->pdo,
            "SELECT w.*,
                (SELECT COUNT(*) FROM votes v WHERE v.wish_id = w.id) AS vote_count,
                (SELECT COUNT(*) FROM votes v WHERE v.wish_id = w.id AND v.voter_id IS NOT NULL) AS anon_count,
                (SELECT MAX(v.created_at) FROM votes v WHERE v.wish_id = w.id) AS last_vote_at,
                EXISTS (SELECT 1 FROM votes v WHERE v.wish_id = w.id AND (v.user_id = :me_u OR v.voter_id = :me_v)) AS voted
             FROM wishes w WHERE $where $tail",
            $params + $tailParams + [
                'me_u' => $caller->userId,
                'me_v' => $caller->userId === null ? $caller->voterId : null,
            ],
        )->fetchAll();
        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Applies the visibility table of the contract.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows, Caller $caller): array
    {
        $details = $caller->seesDetails();
        $admin = $caller->isAdmin;
        $wishedBy = $details ? $this->wishedBy(array_map(static fn (array $r): int => (int) Data::int($r, 'id'), $rows)) : [];
        $out = [];
        foreach ($rows as $r) {
            $id = (int) Data::int($r, 'id');
            $status = (string) Data::str($r, 'status');
            $categories = json_decode(Data::str($r, 'categories') ?? '[]', true);
            $createdByMe = ($caller->userId !== null && Data::int($r, 'created_by_user') === $caller->userId)
                || ($caller->userId === null && $caller->voterId !== null && Data::int($r, 'created_by_voter') === $caller->voterId);
            $out[] = [
                'id' => $id,
                'source' => Data::str($r, 'source'),
                'source_id' => Data::str($r, 'source_id'),
                'title' => Data::str($r, 'title'),
                'image_url' => Data::str($r, 'image_url'),
                'release_date' => Data::str($r, 'release_date'),
                'coming_soon' => Data::int($r, 'coming_soon') === 1,
                'store_url' => Data::str($r, 'store_url'),
                'categories' => is_array($categories) ? array_values(array_filter($categories, 'is_string')) : [],
                'availability' => $status === 'available' ? 'available' : 'not_available',
                'status' => $details ? $status : null,
                'reason' => $details ? Data::str($r, 'reason') : null,
                'note' => $admin ? Data::str($r, 'note') : null,
                'votes' => (int) Data::int($r, 'vote_count'),
                'anonymous_votes' => $admin ? (int) Data::int($r, 'anon_count') : null,
                'voted' => Data::int($r, 'voted') === 1,
                'created_by_me' => $createdByMe,
                'wished_by' => $details ? ($wishedBy[$id] ?? []) : null,
                'gamevault_game_id' => Data::int($r, 'gv_game_id'),
                'created_at' => Data::str($r, 'created_at'),
                'updated_at' => Data::str($r, 'updated_at'),
                'available_at' => Data::str($r, 'available_at'),
            ];
        }
        return $out;
    }

    /**
     * Accounts that created or voted for each wish, in order of their vote.
     *
     * @param list<int> $ids
     * @return array<int, list<array{id: int, username: string}>>
     */
    private function wishedBy(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(', ', array_map('intval', $ids));
        $rows = Database::run(
            $this->pdo,
            "SELECT x.wish_id, u.id, u.username FROM (
                SELECT wish_id, user_id, created_at FROM votes WHERE user_id IS NOT NULL AND wish_id IN ($in)
                UNION ALL
                SELECT id, created_by_user, created_at FROM wishes WHERE created_by_user IS NOT NULL AND id IN ($in)
             ) x JOIN users u ON u.id = x.user_id ORDER BY x.created_at, u.id",
        )->fetchAll();
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $wish = (int) Data::int($row, 'wish_id');
            $user = (int) Data::int($row, 'id');
            if (isset($seen[$wish][$user])) {
                continue;
            }
            $seen[$wish][$user] = true;
            $out[$wish][] = ['id' => $user, 'username' => (string) Data::str($row, 'username')];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status?: string, reason?: ?string, note?: ?string}
     */
    private static function validateUpdate(array $body): array
    {
        $errors = [];
        $changes = [];
        if ($body === []) {
            throw ApiError::validation(['body' => 'required']);
        }
        foreach ($body as $key => $value) {
            switch ($key) {
                case 'status':
                    if (!is_string($value) || !in_array($value, self::STATUSES, true)) {
                        $errors['status'] = 'invalid';
                    } else {
                        $changes['status'] = $value;
                    }
                    break;
                case 'reason':
                case 'note':
                    $max = $key === 'reason' ? 500 : 2000;
                    if ($value !== null && !is_string($value)) {
                        $errors[$key] = 'invalid';
                    } elseif (is_string($value) && mb_strlen($value) > $max) {
                        $errors[$key] = 'too_long';
                    } else {
                        $value = is_string($value) ? trim($value) : null;
                        $changes[$key] = $value === '' ? null : $value;
                    }
                    break;
                default:
                    $errors[$key] = 'invalid';
            }
        }
        if ($errors !== []) {
            throw ApiError::validation($errors);
        }
        return $changes;
    }

    private function now(): string
    {
        return SystemClock::format($this->clock->now());
    }
}
