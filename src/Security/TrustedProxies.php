<?php

declare(strict_types=1);

namespace WishVault\Security;

use WishVault\Http\Request;

/**
 * Client IP and HTTPS detection. Forwarded headers are only honoured when
 * the direct peer is listed in TRUSTED_PROXIES (IPs or CIDR ranges).
 */
final readonly class TrustedProxies
{
    /**
     * @param list<string> $entries
     */
    public function __construct(private array $entries)
    {
    }

    public function isTrusted(string $ip): bool
    {
        foreach ($this->entries as $entry) {
            if (self::inRange($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    public function clientIp(Request $request): string
    {
        $peer = $request->remoteAddr;
        if (!$this->isTrusted($peer)) {
            return $peer;
        }
        $forwarded = $request->header('x-forwarded-for');
        if ($forwarded === null) {
            return $peer;
        }
        $hops = array_values(array_filter(
            array_map('trim', explode(',', $forwarded)),
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false,
        ));
        // walk from the nearest hop; the first untrusted address is the client
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            if (!$this->isTrusted($hops[$i])) {
                return $hops[$i];
            }
        }
        return $hops[0] ?? $peer;
    }

    public function isHttps(Request $request): bool
    {
        if ($request->https) {
            return true;
        }
        if (!$this->isTrusted($request->remoteAddr)) {
            return false;
        }
        $proto = $request->header('x-forwarded-proto');
        if ($proto === null) {
            return false;
        }
        $first = strtolower(trim(explode(',', $proto)[0]));
        return $first === 'https';
    }

    public static function inRange(string $ip, string $range): bool
    {
        $parts = explode('/', $range, 2);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($parts[0]);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $maxBits = strlen($ipBin) * 8;
        $bits = $maxBits;
        if (isset($parts[1])) {
            if (preg_match('/^[0-9]{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $maxBits) {
                return false;
            }
            $bits = (int) $parts[1];
        }
        $fullBytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }
}
