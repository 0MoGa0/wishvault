<?php

declare(strict_types=1);

namespace WishVault\Tests\Unit;

use WishVault\Http\Request;
use WishVault\Security\TrustedProxies;
use WishVault\Tests\TestCase;

final class TrustedProxiesTest extends TestCase
{
    public function testCidrMatching(): void
    {
        self::assertTrue(TrustedProxies::inRange('172.18.0.5', '172.16.0.0/12'));
        self::assertFalse(TrustedProxies::inRange('172.32.0.1', '172.16.0.0/12'));
        self::assertTrue(TrustedProxies::inRange('10.0.0.1', '10.0.0.1'));
        self::assertFalse(TrustedProxies::inRange('10.0.0.2', '10.0.0.1'));
        self::assertTrue(TrustedProxies::inRange('192.168.1.130', '192.168.1.128/25'));
        self::assertFalse(TrustedProxies::inRange('192.168.1.127', '192.168.1.128/25'));
        self::assertTrue(TrustedProxies::inRange('1.2.3.4', '0.0.0.0/0'));
        self::assertTrue(TrustedProxies::inRange('fd00::1', 'fd00::/8'));
        self::assertFalse(TrustedProxies::inRange('fe80::1', 'fd00::/8'));
        self::assertFalse(TrustedProxies::inRange('10.0.0.1', 'fd00::/8'));
        self::assertFalse(TrustedProxies::inRange('10.0.0.1', '10.0.0.0/33'));
        self::assertFalse(TrustedProxies::inRange('garbage', '10.0.0.0/8'));
    }

    public function testForwardedHeadersOnlyFromTrustedProxies(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $headers = ['x-forwarded-for' => '203.0.113.7, 10.0.0.3', 'x-forwarded-proto' => 'https'];

        $viaProxy = new Request('GET', '/', headers: $headers, remoteAddr: '10.0.0.2');
        self::assertSame('203.0.113.7', $proxies->clientIp($viaProxy));
        self::assertTrue($proxies->isHttps($viaProxy));

        $direct = new Request('GET', '/', headers: $headers, remoteAddr: '198.51.100.9');
        self::assertSame('198.51.100.9', $proxies->clientIp($direct));
        self::assertFalse($proxies->isHttps($direct));
    }

    public function testSpoofedLeftmostAddressIsIgnored(): void
    {
        $proxies = new TrustedProxies(['10.0.0.2']);
        $req = new Request('GET', '/', headers: ['x-forwarded-for' => '1.1.1.1, 203.0.113.7'], remoteAddr: '10.0.0.2');
        self::assertSame('203.0.113.7', $proxies->clientIp($req));
    }

    public function testDirectHttps(): void
    {
        $proxies = new TrustedProxies([]);
        self::assertTrue($proxies->isHttps(new Request('GET', '/', https: true)));
    }
}
