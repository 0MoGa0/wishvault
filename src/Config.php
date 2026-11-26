<?php

declare(strict_types=1);

namespace WishVault;

/**
 * Service configuration. Everything comes from environment variables;
 * the image never contains secrets. See README for the full table.
 */
final readonly class Config
{
    public const string VERSION = '0.1.0';

    /**
     * @param list<string> $trustedProxies
     */
    public function __construct(
        public string $gamevaultUrl,
        public string $gamevaultPublicUrl,
        public string $gamevaultUsername,
        public string $gamevaultPassword,
        public int $wishMinRole,
        public int $adminMinRole,
        public bool $autoAvailable,
        public int $syncSeconds,
        public int $steamRecheckHours,
        public string $steamCountry,
        public string $steamLanguage,
        public string $steamTagsLanguage,
        public bool $anonEnabled,
        public int $anonWishLimitPerHour,
        public string $defaultLocale,
        public string $siteName,
        public ?string $siteLogoUrl,
        public ?string $publicUrl,
        public array $trustedProxies,
        public string $theme,
        public ?string $clientDownloadUrl,
        public string $dataDir,
        public string $webDir,
    ) {
    }

    /**
     * @param array<string, string|false> $env defaults to getenv()
     */
    public static function fromEnv(?array $env = null): self
    {
        $get = static function (string $key, string $default = '') use ($env): string {
            $value = $env === null ? getenv($key) : ($env[$key] ?? false);
            return $value === false || $value === '' ? $default : trim($value);
        };
        $int = static fn (string $key, int $default): int => (int) $get($key, (string) $default);
        $bool = static fn (string $key, bool $default): bool => in_array(
            strtolower($get($key, $default ? 'true' : 'false')),
            ['1', 'true', 'yes', 'on'],
            true,
        );
        $nullable = static fn (string $key): ?string => $get($key) === '' ? null : $get($key);

        $gamevaultUrl = rtrim($get('GAMEVAULT_URL'), '/');

        return new self(
            gamevaultUrl: $gamevaultUrl,
            gamevaultPublicUrl: rtrim($get('GAMEVAULT_PUBLIC_URL', $gamevaultUrl), '/'),
            gamevaultUsername: $get('GAMEVAULT_USERNAME'),
            gamevaultPassword: $get('GAMEVAULT_PASSWORD'),
            wishMinRole: max(0, min(3, $int('WISH_MIN_ROLE', 1))),
            adminMinRole: max(0, min(3, $int('ADMIN_MIN_ROLE', 3))),
            autoAvailable: $bool('AUTO_AVAILABLE', true),
            syncSeconds: max(60, $int('SYNC_SECONDS', 900)),
            steamRecheckHours: max(1, $int('STEAM_RECHECK_HOURS', 24)),
            steamCountry: $get('STEAM_COUNTRY', 'US'),
            steamLanguage: $get('STEAM_LANGUAGE', 'english'),
            steamTagsLanguage: $get('STEAM_TAGS_LANGUAGE', 'english'),
            anonEnabled: $bool('ANON_ENABLED', true),
            anonWishLimitPerHour: max(0, $int('ANON_WISH_LIMIT_PER_HOUR', 10)),
            defaultLocale: $get('DEFAULT_LOCALE', 'en'),
            siteName: $get('SITE_NAME', 'WishVault'),
            siteLogoUrl: $nullable('SITE_LOGO_URL'),
            publicUrl: $nullable('PUBLIC_URL'),
            trustedProxies: array_values(array_filter(array_map('trim', explode(',', $get('TRUSTED_PROXIES'))))),
            theme: preg_match('/^[a-z0-9-]{1,32}$/', $get('THEME', 'default')) === 1 ? $get('THEME', 'default') : 'default',
            clientDownloadUrl: $nullable('CLIENT_DOWNLOAD_URL'),
            dataDir: rtrim($get('DATA_DIR', dirname(__DIR__) . '/data'), '/'),
            webDir: rtrim($get('WEB_DIR', dirname(__DIR__) . '/public'), '/'),
        );
    }

    /**
     * Local development: load KEY=VALUE lines from a .env file into the
     * environment. Existing variables win. The image never ships a .env.
     */
    public static function loadDotEnv(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) === 1 && getenv($key) === false) {
                putenv($key . '=' . trim($value, " \t\"'"));
            }
        }
    }

    public function databasePath(): string
    {
        return $this->dataDir . '/wishvault.sqlite';
    }

    public function providerSearchEnabled(): bool
    {
        return $this->gamevaultUrl !== '' && $this->gamevaultUsername !== '' && $this->gamevaultPassword !== '';
    }
}
