<?php

declare(strict_types=1);

namespace WishVault\Tests;

use WishVault\App;
use WishVault\Config;
use WishVault\Db\Database;
use WishVault\Http\Request;
use WishVault\Http\Response;
use WishVault\Tests\Support\FakeHttp;
use WishVault\Tests\Support\FixedClock;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const string GV = 'https://gamevault.example.com';

    protected FakeHttp $http;
    protected FixedClock $clock;
    protected \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp();
        $this->clock = new FixedClock();
        $this->pdo = self::memoryDb();
    }

    /**
     * @param array<string, string> $env
     */
    protected static function config(array $env = []): Config
    {
        return Config::fromEnv($env + [
            'GAMEVAULT_URL' => self::GV,
            'DATA_DIR' => sys_get_temp_dir() . '/wishvault-test-' . getmypid(),
        ]);
    }

    protected static function memoryDb(): \PDO
    {
        return Database::open(':memory:');
    }

    /**
     * App with fake HTTP, fixed clock and the shared in-memory database.
     *
     * @param array<string, string> $env
     */
    protected function app(array $env = []): App
    {
        return new App(self::config($env), $this->pdo, $this->http, $this->clock);
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
        return self::send($app, $method, $path, $json, $headers);
    }

    /**
     * Full request. Write requests get the CSRF header unless `$headers`
     * contains X-Requested-With (use '' to leave it out).
     *
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed>|list<mixed>|null $json
     */
    protected static function send(
        App $app,
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        array $cookies = [],
        string $remoteAddr = '192.0.2.10',
        bool $https = false,
    ): Response {
        $query = [];
        $parts = explode('?', $path, 2);
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
        }
        $headers = array_change_key_case($headers);
        if (!in_array($method, ['GET', 'OPTIONS'], true) && !isset($headers['authorization'])) {
            $headers += ['x-requested-with' => 'WishVault'];
        }
        $headers = array_filter($headers, static fn (string $v): bool => $v !== '');
        /** @var array<string, string> $query */
        return $app->handle(new Request(
            method: $method,
            path: $parts[0],
            query: $query,
            headers: $headers,
            cookies: $cookies,
            body: $json === null ? '' : (string) json_encode($json),
            remoteAddr: $remoteAddr,
            https: $https,
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

    protected static function errorCode(Response $response): string
    {
        $error = self::body($response)['error'] ?? null;
        self::assertIsArray($error);
        self::assertIsString($error['code']);
        return $error['code'];
    }

    /** Value of a Set-Cookie header for `$name`, null if not set. */
    protected static function cookie(Response $response, string $name): ?string
    {
        foreach ($response->cookies as $cookie) {
            if (str_starts_with($cookie, $name . '=')) {
                return $cookie;
            }
        }
        return null;
    }

    protected static function cookieValue(Response $response, string $name): string
    {
        $cookie = self::cookie($response, $name);
        self::assertNotNull($cookie, "cookie $name not set");
        return explode(';', substr($cookie, strlen($name) + 1), 2)[0];
    }

    protected function scalar(string $sql): int
    {
        $value = \WishVault\Db\Database::run($this->pdo, $sql)->fetchColumn();
        self::assertIsNumeric($value);
        return (int) $value;
    }

    /**
     * @return list<mixed>
     */
    protected function column(string $sql): array
    {
        return array_values(\WishVault\Db\Database::run($this->pdo, $sql)->fetchAll(\PDO::FETCH_COLUMN));
    }

    // --- GameVault fakes -----------------------------------------------------

    /** Registers a user token for /api/users/me. */
    protected function gvUser(string $token, int $id, string $username, int $role): void
    {
        $this->http->on('#^' . preg_quote(self::GV, '#') . '/api/users/me$#', static function (string $url, array $headers) use ($token, $id, $username, $role): ?\WishVault\Upstream\HttpResponse {
            if (($headers['Authorization'] ?? '') !== 'Bearer ' . $token) {
                return null;
            }
            return new \WishVault\Upstream\HttpResponse(200, (string) json_encode([
                'id' => $id,
                'username' => $username,
                'role' => $role,
                'activated' => true,
                'deleted_at' => null,
            ]));
        });
    }

    /** Registers a Basic-auth login that returns `$token`. */
    protected function gvLogin(string $username, string $password, string $token): void
    {
        $this->http->on('#/api/auth/basic/login$#', static function (string $url, array $headers) use ($username, $password, $token): ?\WishVault\Upstream\HttpResponse {
            if (($headers['Authorization'] ?? '') !== 'Basic ' . base64_encode($username . ':' . $password)) {
                return null;
            }
            return new \WishVault\Upstream\HttpResponse(200, (string) json_encode(['access_token' => $token, 'refresh_token' => 'r']));
        });
    }

    /**
     * Bearer header for a registered user.
     *
     * @return array<string, string>
     */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /** Standard callers: user `u1` (role 1, id 11), admin `a1` (role 3, id 31), guest `g0` (role 0, id 5). */
    protected function standardUsers(): void
    {
        $this->http->status('#/api/users/me$#', 401);
        $this->gvUser('u1', 11, 'alice', 1);
        $this->gvUser('u2', 12, 'bob', 1);
        $this->gvUser('a1', 31, 'admin', 3);
        $this->gvUser('g0', 5, 'guest', 0);
    }

    // --- Steam fakes ---------------------------------------------------------

    /**
     * @param list<string> $categories
     */
    protected function steamApp(int $appId, string $name, string $type = 'game', bool $comingSoon = false, array $categories = ['Multi-player']): void
    {
        $this->http->json('#store\.steampowered\.com/api/appdetails\?appids=' . $appId . '&#', [
            (string) $appId => [
                'success' => true,
                'data' => [
                    'type' => $type,
                    'name' => $name,
                    'steam_appid' => $appId,
                    'header_image' => "https://cdn.example.com/$appId/header.jpg",
                    'release_date' => ['coming_soon' => $comingSoon, 'date' => $comingSoon ? 'Q3 2027' : 'Apr 18, 2011'],
                    'categories' => array_map(static fn (string $c): array => ['id' => 1, 'description' => $c], $categories),
                ],
            ],
        ]);
    }

    protected function steamUnknown(int $appId): void
    {
        $this->http->json('#store\.steampowered\.com/api/appdetails\?appids=' . $appId . '&#', [(string) $appId => ['success' => false]]);
    }
}
