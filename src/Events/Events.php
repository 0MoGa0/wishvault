<?php

declare(strict_types=1);

namespace WishVault\Events;

use WishVault\Clock;
use WishVault\Db\Database;
use WishVault\Support\Data;
use WishVault\SystemClock;

/**
 * Per-account notifications. An account is interested in a wish when it
 * created it or voted for it. Wish data is stored as a snapshot.
 */
final readonly class Events
{
    public const array TYPES = ['available', 'released', 'rejected'];
    public const int LIST_LIMIT = 100;

    public function __construct(
        private \PDO $pdo,
        private Clock $clock,
    ) {
    }

    /** Creates one event per interested account, returns how many. */
    public function notifyInterested(int $wishId, string $type): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown event type');
        }
        return Database::run(
            $this->pdo,
            "INSERT INTO events (user_id, type, wish_id, wish_title, wish_image_url, gv_game_id, created_at)
             SELECT u.user_id, :type, w.id, w.title, w.image_url, w.gv_game_id, :now
             FROM wishes w
             JOIN (SELECT user_id FROM votes WHERE wish_id = :id AND user_id IS NOT NULL
                   UNION SELECT created_by_user FROM wishes WHERE id = :id AND created_by_user IS NOT NULL) u
             WHERE w.id = :id",
            ['type' => $type, 'now' => SystemClock::format($this->clock->now()), 'id' => $wishId],
        )->rowCount();
    }

    /**
     * @return list<array{id: int, type: string, wish: array{id: int, title: string, image_url: ?string, gamevault_game_id: ?int}, created_at: string, read: bool}>
     */
    public function list(int $userId, bool $unreadOnly): array
    {
        $rows = Database::run(
            $this->pdo,
            'SELECT * FROM events WHERE user_id = :u' . ($unreadOnly ? ' AND read_at IS NULL' : '')
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . self::LIST_LIMIT,
            ['u' => $userId],
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => Data::int($row, 'id') ?? 0,
                'type' => Data::str($row, 'type') ?? '',
                'wish' => [
                    'id' => Data::int($row, 'wish_id') ?? 0,
                    'title' => Data::str($row, 'wish_title') ?? '',
                    'image_url' => Data::str($row, 'wish_image_url'),
                    'gamevault_game_id' => Data::int($row, 'gv_game_id'),
                ],
                'created_at' => Data::str($row, 'created_at') ?? '',
                'read' => Data::str($row, 'read_at') !== null,
            ];
        }
        return $out;
    }

    public function unreadCount(int $userId): int
    {
        return (int) Data::int(Database::run(
            $this->pdo,
            'SELECT COUNT(*) FROM events WHERE user_id = :u AND read_at IS NULL',
            ['u' => $userId],
        )->fetchColumn());
    }

    /**
     * @param list<int> $ids empty = all of the user's events
     */
    public function markRead(int $userId, array $ids): void
    {
        $params = ['u' => $userId, 't' => SystemClock::format($this->clock->now())];
        $sql = 'UPDATE events SET read_at = :t WHERE user_id = :u AND read_at IS NULL';
        if ($ids !== []) {
            $names = [];
            foreach (array_values(array_unique($ids)) as $i => $id) {
                $names[] = ':id' . $i;
                $params['id' . $i] = $id;
            }
            $sql .= ' AND id IN (' . implode(', ', $names) . ')';
        }
        Database::run($this->pdo, $sql, $params);
    }
}
