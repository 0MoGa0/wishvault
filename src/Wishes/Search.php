<?php

declare(strict_types=1);

namespace WishVault\Wishes;

use WishVault\Auth\Caller;
use WishVault\Clock;
use WishVault\Config;
use WishVault\Http\ApiError;
use WishVault\Security\RateLimiter;
use WishVault\Upstream\GameVault;
use WishVault\Upstream\Steam;

/**
 * Steam search (text, AppID or store link) with a fallback to the
 * highest-priority metadata provider of the GameVault server.
 */
final readonly class Search
{
    public const int ACCOUNT_LIMIT_PER_MINUTE = 60;
    public const int ANON_LIMIT_PER_MINUTE = 120;

    public function __construct(
        private Config $config,
        private Steam $steam,
        private GameVault $gameVault,
        private Wishes $wishes,
        private RateLimiter $limiter,
        private Clock $clock,
    ) {
    }

    /**
     * @return array{source: string, fallback: bool, provider: array{slug: string, name: string}|null, results: list<array<string, mixed>>}
     */
    public function search(Caller $caller, string $clientIp, string $query, string $source): array
    {
        if ($caller->isAccount()) {
            $this->limiter->attempt('search:u' . $caller->userId, self::ACCOUNT_LIMIT_PER_MINUTE, 60);
        } else {
            $this->limiter->attempt('search:ip:' . $clientIp, self::ANON_LIMIT_PER_MINUTE, 60);
        }

        if ($source === 'provider') {
            return $this->provider($caller, $query, false);
        }

        $appId = Steam::parseAppId($query);
        $items = [];
        if ($appId !== null) {
            $details = $this->steam->appDetails($appId);
            if ($details !== null && $details['type'] === 'game') {
                $items[] = [
                    'source' => 'steam',
                    'source_id' => (string) $appId,
                    'title' => $details['name'],
                    'image_url' => $details['header_image'],
                    'release_date' => $details['release_date'],
                    'coming_soon' => $details['coming_soon'],
                    'store_url' => Steam::storeUrl($appId),
                ];
            }
        } else {
            foreach ($this->steam->search($query) as $hit) {
                $items[] = [
                    'source' => 'steam',
                    'source_id' => (string) $hit['id'],
                    'title' => $hit['name'],
                    'image_url' => $hit['image'],
                    'release_date' => null,
                    'coming_soon' => false,
                    'store_url' => Steam::storeUrl($hit['id']),
                ];
            }
        }

        if ($items === [] && $appId === null && $this->config->providerSearchEnabled()) {
            return $this->provider($caller, $query, true);
        }

        $results = [];
        foreach ($items as $item) {
            $results[] = $item + $this->wishes->markers($caller, [
                'source' => 'steam',
                'source_id' => $item['source_id'],
                'steam_app_id' => (int) $item['source_id'],
                'provider_key' => null,
                'title' => $item['title'],
            ]);
        }
        return ['source' => 'steam', 'fallback' => false, 'provider' => null, 'results' => $results];
    }

    /**
     * @return array{source: string, fallback: bool, provider: array{slug: string, name: string}|null, results: list<array<string, mixed>>}
     */
    private function provider(Caller $caller, string $query, bool $fallback): array
    {
        if (!$this->config->providerSearchEnabled()) {
            throw ApiError::upstream('provider_unavailable', 'Provider search is not configured');
        }
        $provider = $this->gameVault->activeProvider()
            ?? throw ApiError::upstream('provider_unavailable', 'No active metadata provider');
        $today = $this->clock->now()->format('Y-m-d');
        $results = [];
        foreach ($this->gameVault->providerSearch($provider['slug'], $query) as $item) {
            $this->wishes->rememberProviderItem(['provider_slug' => $provider['slug']] + $item);
            $date = Wishes::providerDate($item['release_date']);
            $result = [
                'source' => 'provider',
                'source_id' => $item['provider_data_id'],
                'title' => $item['title'],
                'image_url' => $item['cover_url'],
                'release_date' => $date,
                'coming_soon' => $date !== null && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date) === 1 && $date > $today,
                'store_url' => null,
            ];
            $results[] = $result + $this->wishes->markers($caller, [
                'source' => 'provider',
                'source_id' => $item['provider_data_id'],
                'steam_app_id' => null,
                'provider_key' => $provider['slug'] . ':' . $item['provider_data_id'],
                'title' => $item['title'],
            ]);
            if (count($results) === 10) {
                break;
            }
        }
        return ['source' => 'provider', 'fallback' => $fallback, 'provider' => $provider, 'results' => $results];
    }
}
