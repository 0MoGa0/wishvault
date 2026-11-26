<?php

declare(strict_types=1);

namespace WishVault\Tests;

use WishVault\App;
use WishVault\Config;
use WishVault\Db\Database;
use WishVault\Http\Request;
use WishVault\Http\Response;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @param array<string, string> $env
     */
    protected static function config(array $env = []): Config
    {
        return Config::fromEnv($env + [
            'GAMEVAULT_URL' => 'https://gamevault.example.com',
            'DATA_DIR' => sys_get_temp_dir() . '/wishvault-test-' . getmypid(),
        ]);
    }

    protected static function memoryDb(): \PDO
    {
        return Database::open(':memory:');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     */
    protected static function call(
        App $app,
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
    ): Response {
        $query = [];
        $parts = explode('?', $path, 2);
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
        }
        /** @var array<string, string> $query */
        return $app->handle(new Request(
            method: $method,
            path: $parts[0],
            query: $query,
            headers: array_change_key_case($headers),
            body: $json === null ? '' : (string) json_encode($json),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function body(Response $response): array
    {
        $data = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        /** @var array<string, mixed> $data */
        return $data;
    }
}
