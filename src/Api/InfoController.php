<?php

declare(strict_types=1);

namespace WishVault\Api;

use WishVault\Config;
use WishVault\Db\Database;
use WishVault\Http\Request;
use WishVault\Http\Response;

final readonly class InfoController
{
    /** Locales shipped with the web UI (web/src/locales). */
    public const array LOCALES = ['en', 'de'];

    /**
     * @param \Closure(): \PDO $db
     */
    public function __construct(
        private Config $config,
        private \Closure $db,
    ) {
    }

    public function info(Request $request): Response
    {
        $lastSync = Database::run(
            ($this->db)(),
            "SELECT json_extract(value, '$') FROM jobs_state WHERE key = 'last_sync_ok_at'",
        )->fetchColumn();

        $provider = Database::run(
            ($this->db)(),
            "SELECT value FROM jobs_state WHERE key = 'provider'",
        )->fetchColumn();
        $provider = is_string($provider) ? json_decode($provider, true) : null;
        $providerName = is_array($provider) && is_string($provider['name'] ?? null) && is_string($provider['slug'] ?? null)
            ? ['slug' => $provider['slug'], 'name' => $provider['name']]
            : null;

        return Response::json([
            'service' => 'wishvault',
            'name' => $this->config->siteName,
            'version' => Config::VERSION,
            'api_version' => '1',
            'logo_url' => $this->config->siteLogoUrl,
            'theme' => $this->config->theme,
            'custom_css' => is_file($this->config->dataDir . '/custom.css'),
            'default_locale' => $this->config->defaultLocale,
            'locales' => self::LOCALES,
            'client_download_url' => $this->config->clientDownloadUrl,
            'gamevault_url' => $this->config->gamevaultPublicUrl,
            'features' => [
                'anonymous' => $this->config->anonEnabled,
                'provider_search' => $this->config->providerSearchEnabled(),
                'web_login' => $this->config->gamevaultUrl !== '',
            ],
            'roles' => [
                'wish_min_role' => $this->config->wishMinRole,
                'admin_min_role' => $this->config->adminMinRole,
            ],
            'provider' => $this->config->providerSearchEnabled() ? $providerName : null,
            'sync_interval_seconds' => $this->config->syncSeconds,
            'last_sync_at' => is_string($lastSync) ? $lastSync : null,
        ])->withHeader('Cache-Control', 'no-cache');
    }
}
