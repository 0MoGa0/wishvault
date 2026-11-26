<?php

declare(strict_types=1);

namespace WishVault\Tests\Unit;

use WishVault\Config;
use WishVault\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $c = Config::fromEnv([]);
        self::assertSame(1, $c->wishMinRole);
        self::assertSame(3, $c->adminMinRole);
        self::assertSame(900, $c->syncSeconds);
        self::assertTrue($c->anonEnabled);
        self::assertSame('default', $c->theme);
        self::assertNull($c->clientDownloadUrl);
        self::assertFalse($c->providerSearchEnabled());
    }

    public function testParsesAndClamps(): void
    {
        $c = Config::fromEnv([
            'GAMEVAULT_URL' => 'https://gv.example.com/',
            'GAMEVAULT_USERNAME' => 'bot',
            'GAMEVAULT_PASSWORD' => 'secret',
            'ADMIN_MIN_ROLE' => '9',
            'SYNC_SECONDS' => '5',
            'ANON_ENABLED' => 'off',
            'THEME' => '../evil',
            'TRUSTED_PROXIES' => '10.0.0.1, 172.16.0.0/12,',
        ]);
        self::assertSame('https://gv.example.com', $c->gamevaultUrl);
        self::assertSame('https://gv.example.com', $c->gamevaultPublicUrl);
        self::assertSame(3, $c->adminMinRole);
        self::assertSame(60, $c->syncSeconds);
        self::assertFalse($c->anonEnabled);
        self::assertSame('default', $c->theme);
        self::assertSame(['10.0.0.1', '172.16.0.0/12'], $c->trustedProxies);
        self::assertTrue($c->providerSearchEnabled());
    }
}
