<?php

declare(strict_types=1);

namespace WishVault\Upstream;

use WishVault\Config;
use WishVault\Db\Cache;
use WishVault\Http\ApiError;
use WishVault\Support\Data;

/**
 * Public Steam store endpoints (no API key).
 */
final readonly class Steam
{
    public const string STORE = 'https://store.steampowered.com';
    public const string API = 'https://api.steampowered.com';
    public const int MAX_APP_ID = 4294967295;

    public function __construct(
        private Config $config,
        private HttpClient $http,
        private Cache $cache,
    ) {
    }

    /** AppID from a plain number or a store/community/SteamDB link, otherwise null. */
    public static function parseAppId(string $query): ?int
    {
        $query = trim($query);
        if (preg_match('/^[0-9]{1,10}$/', $query) === 1) {
            return self::validAppId($query);
        }
        if (preg_match('#(?:^|//|\.)(?:store\.steampowered\.com|steamcommunity\.com|steamdb\.info)/app/([0-9]{1,10})(?:[/?\#]|$)#i', $query, $m) === 1) {
            return self::validAppId($m[1]);
        }
        return null;
    }

    public static function validAppId(string $value): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/', $value) !== 1 || (int) $value > self::MAX_APP_ID) {
            return null;
        }
        return (int) $value;
    }

    public static function storeUrl(int $appId): string
    {
        return self::STORE . '/app/' . $appId . '/';
    }

    /**
     * Store search, apps only, at most 10 (cached 1 h).
     *
     * @return list<array{id: int, name: string, image: ?string}>
     */
    public function search(string $term): array
    {
        $key = 'steam:search:' . $this->config->steamLanguage . ':' . $this->config->steamCountry . ':'
            . hash('sha256', mb_strtolower(trim($term)));
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return self::parseSearchItems($cached);
        }
        $json = $this->getJson(self::STORE . '/api/storesearch/?' . http_build_query([
            'term' => $term,
            'l' => $this->config->steamLanguage,
            'cc' => $this->config->steamCountry,
        ]));
        $items = self::parseSearchItems(Data::rows($json, 'items'));
        $this->cache->set($key, $items, 3600);
        return $items;
    }

    /**
     * App details, null if Steam does not know the app (cached 6 h, misses 1 h).
     *
     * @return array{app_id: int, type: string, name: string, header_image: ?string, release_date: ?string, coming_soon: bool, categories: list<string>}|null
     */
    public function appDetails(int $appId, bool $useCache = true): ?array
    {
        $key = 'steam:app:' . $appId . ':' . $this->config->steamLanguage . ':' . $this->config->steamCountry;
        if ($useCache) {
            $cached = $this->cache->get($key);
            if ($cached === false) {
                return null;
            }
            if (is_array($cached)) {
                $parsed = self::parseDetails($appId, $cached);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }
        $data = $this->fetchDetails($appId, true);
        // region-locked apps fail with a country code
        $data ??= $this->fetchDetails($appId, false);
        $details = $data === null ? null : self::parseDetails($appId, $data);
        $this->cache->set($key, $details === null ? false : $data, $details === null ? 3600 : 6 * 3600);
        return $details;
    }

    /**
     * User tags, most relevant first (cached 7 days, empty result 1 h).
     *
     * @return list<string>
     */
    public function tags(int $appId): array
    {
        $lang = $this->config->steamTagsLanguage;
        $key = 'steam:tags:' . $appId . ':' . $lang;
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_string'));
        }
        $names = $this->tagNames($lang);
        $json = $this->getJson(self::API . '/IStoreBrowseService/GetItems/v1/?' . http_build_query([
            'input_json' => json_encode([
                'ids' => [['appid' => $appId]],
                'context' => ['language' => $lang, 'country_code' => $this->config->steamCountry],
                'data_request' => ['include_tag_count' => 20],
            ], JSON_THROW_ON_ERROR),
        ]));
        $item = Data::rows(Data::arr($json, 'response'), 'store_items')[0] ?? [];
        $tags = Data::rows($item, 'tags');
        usort($tags, static fn (array $a, array $b): int => (Data::int($b, 'weight') ?? 0) <=> (Data::int($a, 'weight') ?? 0));
        $out = [];
        foreach ($tags as $tag) {
            $name = $names[Data::int($tag, 'tagid') ?? -1] ?? null;
            if ($name !== null) {
                $out[] = $name;
            }
        }
        $this->cache->set($key, $out, $out === [] ? 3600 : 7 * 86400);
        return $out;
    }

    /**
     * @return array<int, string> tag id → name
     */
    private function tagNames(string $lang): array
    {
        $key = 'steam:taglist:' . $lang;
        $cached = $this->cache->get($key);
        $rows = is_array($cached) ? Data::rows($cached) : null;
        if ($rows === null) {
            $json = $this->getJson(self::API . '/IStoreService/GetTagList/v1/?' . http_build_query(['language' => $lang]));
            $rows = Data::rows(Data::arr($json, 'response'), 'tags');
            $this->cache->set($key, $rows, 7 * 86400);
        }
        $names = [];
        foreach ($rows as $row) {
            $id = Data::int($row, 'tagid');
            $name = Data::str($row, 'name');
            if ($id !== null && $name !== null) {
                $names[$id] = $name;
            }
        }
        return $names;
    }

    /**
     * @return array<array-key, mixed>|null `data` of a successful answer
     */
    private function fetchDetails(int $appId, bool $withCountry): ?array
    {
        $query = [
            'appids' => $appId,
            'l' => $this->config->steamLanguage,
            'filters' => 'basic,release_date,categories',
        ];
        if ($withCountry) {
            $query['cc'] = $this->config->steamCountry;
        }
        $json = $this->getJson(self::STORE . '/api/appdetails?' . http_build_query($query), allow404: true);
        if ($json === null) {
            return null;
        }
        if (!is_array($json)) {
            throw ApiError::upstream('steam_unavailable', 'Unexpected appdetails response');
        }
        $entry = Data::arr($json, (string) $appId);
        if (Data::bool($entry, 'success') !== true) {
            return null;
        }
        return Data::arr($entry, 'data');
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array{app_id: int, type: string, name: string, header_image: ?string, release_date: ?string, coming_soon: bool, categories: list<string>}|null
     */
    private static function parseDetails(int $appId, array $data): ?array
    {
        $name = Data::str($data, 'name');
        if ($name === null || trim($name) === '') {
            return null;
        }
        $release = Data::arr($data, 'release_date');
        $date = Data::str($release, 'date');
        $categories = [];
        foreach (Data::rows($data, 'categories') as $cat) {
            $desc = Data::str($cat, 'description');
            if ($desc !== null && $desc !== '' && !in_array($desc, $categories, true)) {
                $categories[] = $desc;
            }
        }
        return [
            'app_id' => $appId,
            'type' => strtolower(Data::str($data, 'type') ?? ''),
            'name' => trim($name),
            'header_image' => Data::str($data, 'header_image'),
            'release_date' => $date === null || trim($date) === '' ? null : trim($date),
            'coming_soon' => Data::bool($release, 'coming_soon') ?? false,
            'categories' => $categories,
        ];
    }

    /**
     * @param array<array-key, mixed> $items
     * @return list<array{id: int, name: string, image: ?string}>
     */
    private static function parseSearchItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $type = Data::str($item, 'type');
            $id = Data::int($item, 'id');
            $name = Data::str($item, 'name');
            if (($type !== null && $type !== 'app') || $id === null || $id < 1 || $name === null) {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $name, 'image' => Data::str($item, 'tiny_image') ?? Data::str($item, 'image')];
            if (count($out) === 10) {
                break;
            }
        }
        return $out;
    }

    /** Decoded JSON; null for a 404 when allowed. */
    private function getJson(string $url, bool $allow404 = false): mixed
    {
        try {
            $res = $this->http->get($url, ['Accept' => 'application/json'], new HttpOptions(
                timeout: 15,
                followRedirects: true,
                maxBytes: 4 * 1024 * 1024,
            ));
        } catch (HttpException) {
            throw ApiError::upstream('steam_unavailable', 'Steam not reachable');
        }
        if ($res->notFound() && $allow404) {
            return null;
        }
        if (!$res->ok()) {
            throw ApiError::upstream('steam_unavailable', 'Steam answered ' . $res->status);
        }
        $json = $res->json();
        if ($json === null) {
            // Steam answers `null` when throttling appdetails
            throw ApiError::upstream('steam_unavailable', 'Empty or invalid answer from Steam');
        }
        return $json;
    }
}
