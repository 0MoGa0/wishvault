<?php

declare(strict_types=1);

namespace WishVault\Worker;

use WishVault\Clock;

/**
 * Daily SQLite snapshot via VACUUM INTO, the newest seven are kept.
 */
final readonly class Backup
{
    public const int KEEP = 7;

    public function __construct(
        private \PDO $pdo,
        private string $dir,
        private Clock $clock,
    ) {
    }

    /** Writes today's backup and returns its path. */
    public function run(): string
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0o770, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create backup directory');
        }
        $file = $this->dir . '/wishvault-' . $this->clock->now()->format('Y-m-d') . '.sqlite';
        if (is_file($file)) {
            unlink($file);
        }
        $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($file));
        $this->prune();
        return $file;
    }

    private function prune(): void
    {
        $files = glob($this->dir . '/wishvault-*.sqlite') ?: [];
        rsort($files, SORT_STRING);
        foreach (array_slice($files, self::KEEP) as $old) {
            unlink($old);
        }
    }
}
