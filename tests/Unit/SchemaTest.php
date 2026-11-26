<?php

declare(strict_types=1);

namespace WishVault\Tests\Unit;

use WishVault\Db\Database;
use WishVault\Db\Schema;
use WishVault\Tests\TestCase;

final class SchemaTest extends TestCase
{
    public function testMigratesToLatestVersion(): void
    {
        $pdo = self::memoryDb();
        self::assertSame(count(Schema::migrations()), Schema::version($pdo));
        $tables = Database::run($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(
            ['cache', 'events', 'gv_games', 'jobs_state', 'rate_hits', 'sessions', 'users', 'voters', 'votes', 'wishes'],
            $tables,
        );
    }

    public function testMigrateIsIdempotent(): void
    {
        $pdo = self::memoryDb();
        Schema::migrate($pdo);
        self::assertSame(count(Schema::migrations()), Schema::version($pdo));
    }

    public function testRefusesNewerSchema(): void
    {
        $pdo = self::memoryDb();
        $pdo->exec('PRAGMA user_version = 999');
        $this->expectException(\RuntimeException::class);
        Schema::migrate($pdo);
    }

    public function testVoteNeedsExactlyOneOwner(): void
    {
        $pdo = self::memoryDb();
        $pdo->exec("INSERT INTO wishes (source, source_id, title, title_normalized, created_at, updated_at)
                    VALUES ('steam', '620', 'Portal 2', 'portal2', 'x', 'x')");
        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO votes (wish_id, created_at) VALUES (1, 'x')");
    }
}
