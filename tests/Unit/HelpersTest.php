<?php

declare(strict_types=1);

namespace WishVault\Tests\Unit;

use WishVault\Support\Data;
use WishVault\Support\Title;
use WishVault\Tests\TestCase;
use WishVault\Upstream\CurlHttpClient;
use WishVault\Upstream\GameVault;
use WishVault\Upstream\HttpException;
use WishVault\Upstream\Steam;

final class HelpersTest extends TestCase
{
    public function testTitleNormalization(): void
    {
        self::assertSame('portal2', Title::normalize('Portal 2'));
        self::assertSame('portal2', Title::normalize('PORTAL™ 2'));
        self::assertSame('pokemonredandblue', Title::normalize('Pokémon Red & Blue'));
        self::assertSame('ii', Title::normalize('II'));
        self::assertSame('', Title::normalize('™®©'));
    }

    public function testSteamAppIdDetection(): void
    {
        self::assertSame(620, Steam::parseAppId('620'));
        self::assertSame(620, Steam::parseAppId(' https://store.steampowered.com/app/620/Portal_2/ '));
        self::assertSame(620, Steam::parseAppId('steamcommunity.com/app/620'));
        self::assertSame(620, Steam::parseAppId('https://steamdb.info/app/620/charts/'));
        self::assertNull(Steam::parseAppId('0'));
        self::assertNull(Steam::parseAppId('99999999999'));
        self::assertNull(Steam::parseAppId('9999999999'));
        self::assertNull(Steam::parseAppId('https://evil.example.com/app/620'));
        self::assertNull(Steam::parseAppId('portal 2'));
    }

    public function testDataAccessors(): void
    {
        $d = ['i' => '42', 'f' => 3.0, 's' => 'x', 'b' => false, 'a' => [1, 'x', [2]]];
        self::assertSame(42, Data::int($d, 'i'));
        self::assertSame(3, Data::int($d, 'f'));
        self::assertNull(Data::int($d, 's'));
        self::assertSame('x', Data::str($d, 's'));
        self::assertFalse(Data::bool($d, 'b'));
        self::assertSame([[2]], Data::rows($d, 'a'));
        self::assertNull(Data::arr('nope', 'a'));
    }

    public function testJwtTtl(): void
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['exp' => 2000])), '+/', '-_'), '=');
        self::assertSame(940, GameVault::tokenTtl("h.$payload.s", 1000));
        self::assertSame(30, GameVault::tokenTtl("h.$payload.s", 1990));
        self::assertSame(600, GameVault::tokenTtl('opaque', 1000));
    }

    public function testCurlRejectsOtherSchemes(): void
    {
        $this->expectException(HttpException::class);
        (new CurlHttpClient())->get('file:///etc/passwd');
    }
}
