<?php

declare(strict_types=1);

namespace WishVault\Library;

use WishVault\Db\Database;
use WishVault\Support\Data;

/**
 * Finds a game of the mirrored GameVault library for a wish.
 * Order: provider id (exact) → Steam AppID → normalized title, the title
 * only when the game has no contradicting id. Removed games never match.
 */
final readonly class Matcher
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param string|null $providerKey "slug:id"
     */
    public function match(?string $providerKey, ?int $steamAppId, string $titleNormalized): ?int
    {
        if ($providerKey !== null) {
            $id = Database::run(
                $this->pdo,
                'SELECT g.id FROM gv_games g WHERE g.removed_at IS NULL
                 AND EXISTS (SELECT 1 FROM json_each(g.provider_ids) j WHERE j.value = :k) ORDER BY g.id LIMIT 1',
                ['k' => $providerKey],
            )->fetchColumn();
            if (is_numeric($id)) {
                return (int) $id;
            }
        }
        if ($steamAppId !== null) {
            $id = Database::run(
                $this->pdo,
                'SELECT g.id FROM gv_games g WHERE g.removed_at IS NULL
                 AND EXISTS (SELECT 1 FROM json_each(g.steam_app_ids) j WHERE j.value = :a) ORDER BY g.id LIMIT 1',
                ['a' => $steamAppId],
            )->fetchColumn();
            if (is_numeric($id)) {
                return (int) $id;
            }
        }
        if ($titleNormalized === '') {
            return null;
        }
        $rows = Database::run(
            $this->pdo,
            'SELECT id, steam_app_ids, provider_ids FROM gv_games
             WHERE removed_at IS NULL AND title_normalized = :t ORDER BY id',
            ['t' => $titleNormalized],
        )->fetchAll();
        foreach ($rows as $row) {
            if (!self::contradicts($row, $providerKey, $steamAppId)) {
                return Data::int($row, 'id');
            }
        }
        return null;
    }

    private static function contradicts(mixed $row, ?string $providerKey, ?int $steamAppId): bool
    {
        $steamIds = array_map('intval', array_filter(self::jsonList(Data::str($row, 'steam_app_ids')), 'is_numeric'));
        if ($steamAppId !== null && $steamIds !== [] && !in_array($steamAppId, $steamIds, true)) {
            return true;
        }
        if ($providerKey !== null) {
            $slug = strstr($providerKey, ':', true);
            foreach (array_filter(self::jsonList(Data::str($row, 'provider_ids')), 'is_string') as $key) {
                if ($slug !== false && str_starts_with($key, $slug . ':') && $key !== $providerKey) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return list<mixed>
     */
    private static function jsonList(?string $json): array
    {
        $data = json_decode($json ?? '[]', true);
        return is_array($data) ? array_values($data) : [];
    }
}
