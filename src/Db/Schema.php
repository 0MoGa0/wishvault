<?php

declare(strict_types=1);

namespace WishVault\Db;

/**
 * Schema migrations, tracked with PRAGMA user_version.
 * Never edit a released migration; append a new one.
 * All timestamps are stored as ISO 8601 UTC text ("2026-01-31T12:00:00Z").
 */
final class Schema
{
    /**
     * @return list<string> index + 1 = version
     */
    public static function migrations(): array
    {
        return [
            // v1: initial schema
            <<<'SQL'
            CREATE TABLE users (
                id                      INTEGER PRIMARY KEY,          -- GameVault user id
                username                TEXT    NOT NULL,
                role                    INTEGER NOT NULL,
                first_seen_at           TEXT    NOT NULL,
                last_seen_at            TEXT    NOT NULL
            );

            -- anonymous web visitors; token is stored as SHA-256 hex only
            CREATE TABLE voters (
                id                      INTEGER PRIMARY KEY,
                token_hash              TEXT    NOT NULL UNIQUE,
                created_at              TEXT    NOT NULL,
                last_seen_at            TEXT    NOT NULL
            );

            -- web sessions; token is stored as SHA-256 hex only
            CREATE TABLE sessions (
                token_hash              TEXT    PRIMARY KEY,
                user_id                 INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                created_at              TEXT    NOT NULL,
                expires_at              TEXT    NOT NULL,
                last_seen_at            TEXT    NOT NULL
            );
            CREATE INDEX sessions_user ON sessions(user_id);

            CREATE TABLE wishes (
                id                      INTEGER PRIMARY KEY,
                source                  TEXT    NOT NULL CHECK (source IN ('steam', 'provider')),
                source_id               TEXT    NOT NULL,             -- Steam AppID or provider data id
                provider_slug           TEXT,                         -- set when source = 'provider'
                steam_app_id            INTEGER,                      -- also filled for provider wishes when known
                title                   TEXT    NOT NULL,
                title_normalized        TEXT    NOT NULL,
                image_url               TEXT,
                release_date            TEXT,
                coming_soon             INTEGER NOT NULL DEFAULT 0,
                store_url               TEXT,
                categories              TEXT    NOT NULL DEFAULT '[]', -- JSON array of strings
                status                  TEXT    NOT NULL DEFAULT 'new'
                                        CHECK (status IN ('new', 'queued', 'paused', 'rejected', 'available')),
                reason                  TEXT,
                note                    TEXT,
                gv_game_id              INTEGER,
                created_by_user         INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_by_voter        INTEGER REFERENCES voters(id) ON DELETE SET NULL,
                created_at              TEXT    NOT NULL,
                updated_at              TEXT    NOT NULL,
                available_at            TEXT,
                details_checked_at      TEXT,                         -- last Steam release re-check
                UNIQUE (source, source_id)
            );
            CREATE INDEX wishes_status ON wishes(status);
            CREATE INDEX wishes_steam ON wishes(steam_app_id);
            CREATE INDEX wishes_title ON wishes(title_normalized);

            -- exactly one of user_id / voter_id is set
            CREATE TABLE votes (
                id                      INTEGER PRIMARY KEY,
                wish_id                 INTEGER NOT NULL REFERENCES wishes(id) ON DELETE CASCADE,
                user_id                 INTEGER REFERENCES users(id) ON DELETE CASCADE,
                voter_id                INTEGER REFERENCES voters(id) ON DELETE CASCADE,
                created_at              TEXT    NOT NULL,
                CHECK ((user_id IS NULL) <> (voter_id IS NULL))
            );
            CREATE UNIQUE INDEX votes_user ON votes(wish_id, user_id) WHERE user_id IS NOT NULL;
            CREATE UNIQUE INDEX votes_voter ON votes(wish_id, voter_id) WHERE voter_id IS NOT NULL;
            CREATE INDEX votes_user_lookup ON votes(user_id);
            CREATE INDEX votes_voter_lookup ON votes(voter_id);

            -- mirror of the GameVault library
            CREATE TABLE gv_games (
                id                      INTEGER PRIMARY KEY,          -- GameVault game id
                title                   TEXT    NOT NULL,
                title_normalized        TEXT    NOT NULL,
                steam_app_ids           TEXT    NOT NULL DEFAULT '[]', -- JSON array of ints
                provider_ids            TEXT    NOT NULL DEFAULT '[]', -- JSON array of "slug:id"
                synced_at               TEXT    NOT NULL,
                removed_at              TEXT                          -- not seen in the last complete sync
            );
            CREATE INDEX gv_games_title ON gv_games(title_normalized);

            -- per-account notifications; wish data is a snapshot (wish may be deleted later)
            CREATE TABLE events (
                id                      INTEGER PRIMARY KEY,
                user_id                 INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type                    TEXT    NOT NULL CHECK (type IN ('available', 'released', 'rejected')),
                wish_id                 INTEGER,
                wish_title              TEXT    NOT NULL,
                wish_image_url          TEXT,
                gv_game_id              INTEGER,
                created_at              TEXT    NOT NULL,
                read_at                 TEXT
            );
            CREATE INDEX events_user ON events(user_id, read_at);

            -- worker state: lock, heartbeat, sync flag, last results (key/value, JSON values)
            CREATE TABLE jobs_state (
                key                     TEXT    PRIMARY KEY,
                value                   TEXT    NOT NULL,
                updated_at              TEXT    NOT NULL
            );

            -- HTTP response cache (Steam, GameVault token checks)
            CREATE TABLE cache (
                key                     TEXT    PRIMARY KEY,
                value                   TEXT    NOT NULL,
                expires_at              INTEGER NOT NULL              -- unix time
            );
            CREATE INDEX cache_expires ON cache(expires_at);

            -- rate limits: failed logins, anonymous wishes (bucket = 'login:<ip>', 'wish:<ip>', 'wish:v<voter>')
            CREATE TABLE rate_hits (
                bucket                  TEXT    NOT NULL,
                hit_at                  INTEGER NOT NULL              -- unix time
            );
            CREATE INDEX rate_hits_bucket ON rate_hits(bucket, hit_at);
            SQL,
        ];
    }

    public static function version(\PDO $pdo): int
    {
        $version = Database::run($pdo, 'PRAGMA user_version')->fetchColumn();
        return is_numeric($version) ? (int) $version : 0;
    }

    public static function migrate(\PDO $pdo): void
    {
        $migrations = self::migrations();
        $current = self::version($pdo);
        if ($current > count($migrations)) {
            throw new \RuntimeException(sprintf(
                'Database schema v%d is newer than this version of WishVault (v%d)',
                $current,
                count($migrations),
            ));
        }
        for ($v = $current; $v < count($migrations); $v++) {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                // another process may have migrated meanwhile
                if (self::version($pdo) !== $v) {
                    $pdo->exec('ROLLBACK');
                    continue;
                }
                $pdo->exec($migrations[$v]);
                $pdo->exec('PRAGMA user_version = ' . ($v + 1));
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }
    }
}
