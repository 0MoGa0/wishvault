<?php

declare(strict_types=1);

namespace WishVault\Upstream;

use WishVault\Clock;
use WishVault\Config;
use WishVault\Db\Cache;
use WishVault\Http\ApiError;
use WishVault\Support\Data;

/**
 * GameVault server API. Only the configured GAMEVAULT_URL is ever requested
 * and redirects are never followed. User tokens are only used for the call
 * and cached under their SHA-256; the bot token lives in process memory only.
 */
final class GameVault
{
    public const int ME_CACHE_SECONDS = 300;

    private ?string $botToken = null;
    private int $botTokenExpires = 0;

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly Cache $cache,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Validates a user's access token (cached for 5 minutes).
     *
     * @return array{id: int, username: string, role: int}
     * @throws ApiError unauthorized | gamevault_unavailable
     */
    public function userForToken(string $token): array
    {
        $key = 'gv:me:' . hash('sha256', $token);
        $cached = self::parseUser($this->cache->get($key));
        if ($cached !== null) {
            return $cached;
        }
        $user = $this->me($token);
        if ($user === null) {
            throw ApiError::unauthorized('Token invalid or expired');
        }
        $this->cache->set($key, $user, self::ME_CACHE_SECONDS);
        return $user;
    }

    /**
     * Basic-auth login, returns the user. The GameVault token is discarded.
     *
     * @return array{id: int, username: string, role: int}
     * @throws ApiError login_failed | gamevault_unavailable
     */
    public function login(string $username, string $password): array
    {
        $token = $this->basicLogin($username, $password);
        if ($token === null) {
            throw new ApiError(401, 'login_failed', 'Wrong credentials or account disabled');
        }
        $user = $this->me($token);
        if ($user === null) {
            throw new ApiError(401, 'login_failed', 'Account not usable');
        }
        return $user;
    }

    /**
     * Enabled metadata provider with the highest priority (cached 1 h).
     *
     * @return array{slug: string, name: string}|null
     */
    public function activeProvider(): ?array
    {
        $cached = $this->cache->get('gv:provider');
        if (is_array($cached)) {
            $slug = Data::str($cached, 'slug');
            $name = Data::str($cached, 'name');
            return $slug !== null && $name !== null ? ['slug' => $slug, 'name' => $name] : null;
        }
        $res = $this->botGet('/api/metadata/providers');
        $best = null;
        $bestPriority = PHP_INT_MIN;
        foreach (Data::rows($this->decode($res)) as $p) {
            $slug = Data::str($p, 'slug');
            $priority = Data::int($p, 'priority') ?? 0;
            if ($slug === null || Data::bool($p, 'enabled') === false || $priority <= $bestPriority) {
                continue;
            }
            $best = ['slug' => $slug, 'name' => Data::str($p, 'name') ?? $slug];
            $bestPriority = $priority;
        }
        $this->cache->set('gv:provider', $best ?? [], 3600);
        return $best;
    }

    /**
     * @return list<array{provider_slug: string, provider_data_id: string, title: string, release_date: ?string, cover_url: ?string}>
     */
    public function providerSearch(string $slug, string $query): array
    {
        $res = $this->botGet(
            '/api/metadata/providers/' . rawurlencode($slug) . '/search?' . http_build_query(['query' => $query]),
        );
        $out = [];
        foreach (Data::rows($this->decode($res)) as $item) {
            $id = Data::str($item, 'provider_data_id') ?? (string) (Data::int($item, 'provider_data_id') ?? '');
            $title = Data::str($item, 'title');
            if ($id === '' || $title === null || strlen($id) > 64) {
                continue;
            }
            $out[] = [
                'provider_slug' => Data::str($item, 'provider_slug') ?? $slug,
                'provider_data_id' => $id,
                'title' => $title,
                'release_date' => Data::str($item, 'release_date'),
                'cover_url' => Data::str($item, 'cover_url'),
            ];
        }
        return $out;
    }

    /**
     * One page of the library (nestjs-paginate).
     *
     * @return array{data: list<array<array-key, mixed>>, total_pages: int}
     */
    public function gamesPage(int $page, int $limit): array
    {
        $res = $this->botGet('/api/games?' . http_build_query([
            'page' => $page,
            'limit' => $limit,
            'sortBy' => 'id:ASC',
        ]));
        $json = $this->decode($res);
        if (!is_array($json) || !is_array($json['data'] ?? null)) {
            throw ApiError::upstream('gamevault_unavailable', 'Unexpected games response');
        }
        return [
            'data' => Data::rows($json, 'data'),
            'total_pages' => Data::int(Data::arr($json, 'meta'), 'totalPages') ?? 1,
        ];
    }

    /**
     * Full game incl. provider_metadata, null if it does not exist.
     *
     * @return array<array-key, mixed>|null
     */
    public function game(int $id): ?array
    {
        $res = $this->botGet('/api/games/' . $id, allow404: true);
        if ($res->notFound()) {
            return null;
        }
        return Data::arr($this->decode($res));
    }

    /**
     * @return array{id: int, username: string, role: int}|null null if the token is rejected
     */
    private function me(string $token): ?array
    {
        $res = $this->request('/api/users/me', ['Authorization' => 'Bearer ' . $token]);
        if (in_array($res->status, [401, 403, 404, 406], true)) {
            return null;
        }
        $json = $this->decode($res);
        if (Data::str($json, 'deleted_at') !== null || Data::bool($json, 'activated') === false) {
            return null;
        }
        $user = self::parseUser($json);
        if ($user === null) {
            throw ApiError::upstream('gamevault_unavailable', 'Unexpected user response');
        }
        return $user;
    }

    /** Access token, or null when the credentials are rejected. */
    private function basicLogin(string $username, string $password): ?string
    {
        $res = $this->request('/api/auth/basic/login', [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
        ]);
        if (in_array($res->status, [400, 401, 403, 404, 406], true)) {
            return null;
        }
        $token = Data::str($this->decode($res), 'access_token');
        if ($token === null || $token === '') {
            throw ApiError::upstream('gamevault_unavailable', 'Unexpected login response');
        }
        return $token;
    }

    private function botGet(string $path, bool $allow404 = false): HttpResponse
    {
        if (!$this->config->providerSearchEnabled()) {
            throw ApiError::upstream('gamevault_unavailable', 'Bot account not configured');
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->botToken();
            $res = $this->request($path, ['Authorization' => 'Bearer ' . $token]);
            if ($res->status !== 401) {
                if ($res->notFound() && $allow404) {
                    return $res;
                }
                if ($res->status === 403) {
                    throw ApiError::upstream('provider_unavailable', 'Bot account lacks permissions');
                }
                return $res;
            }
            $this->botToken = null;
        }
        throw ApiError::upstream('gamevault_unavailable', 'Bot token rejected');
    }

    private function botToken(): string
    {
        $now = $this->clock->now()->getTimestamp();
        if ($this->botToken !== null && $this->botTokenExpires > $now) {
            return $this->botToken;
        }
        $token = $this->basicLogin($this->config->gamevaultUsername, $this->config->gamevaultPassword);
        if ($token === null) {
            throw ApiError::upstream('gamevault_unavailable', 'Bot login failed');
        }
        $this->botToken = $token;
        $this->botTokenExpires = $now + self::tokenTtl($token, $now);
        return $token;
    }

    /** JWT exp − now − 60 s (at least 30 s); 600 s if the token has no readable exp. */
    public static function tokenTtl(string $jwt, int $now): int
    {
        $parts = explode('.', $jwt);
        if (count($parts) === 3) {
            $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
            $exp = $payload === false ? null : Data::int(json_decode($payload, true), 'exp');
            if ($exp !== null) {
                return max(30, $exp - $now - 60);
            }
        }
        return 600;
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $path, array $headers): HttpResponse
    {
        if ($this->config->gamevaultUrl === '') {
            throw ApiError::upstream('gamevault_unavailable', 'GAMEVAULT_URL not configured');
        }
        try {
            $res = $this->http->get(
                $this->config->gamevaultUrl . $path,
                $headers + ['Accept' => 'application/json'],
                new HttpOptions(timeout: 20, followRedirects: false, maxBytes: 32 * 1024 * 1024),
            );
        } catch (HttpException) {
            throw ApiError::upstream('gamevault_unavailable', 'GameVault not reachable');
        }
        if ($res->serverError() || $res->rateLimited() || ($res->status >= 300 && $res->status < 400)) {
            throw ApiError::upstream('gamevault_unavailable', 'GameVault answered ' . $res->status);
        }
        return $res;
    }

    private function decode(HttpResponse $res): mixed
    {
        if (!$res->ok()) {
            throw ApiError::upstream('gamevault_unavailable', 'GameVault answered ' . $res->status);
        }
        $json = $res->json();
        if ($json === null) {
            throw ApiError::upstream('gamevault_unavailable', 'Invalid JSON from GameVault');
        }
        return $json;
    }

    /**
     * @return array{id: int, username: string, role: int}|null
     */
    private static function parseUser(mixed $json): ?array
    {
        $id = Data::int($json, 'id');
        $username = Data::str($json, 'username');
        $role = Data::int($json, 'role');
        if ($id === null || $username === null || $role === null || $role < 0 || $role > 3) {
            return null;
        }
        return ['id' => $id, 'username' => $username, 'role' => $role];
    }
}
