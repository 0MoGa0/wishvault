<?php

declare(strict_types=1);

namespace WishVault\Http;

use WishVault\Auth\Caller;

/**
 * Per-request state shared by the controllers: client address, HTTPS flag,
 * the resolved caller and cookies to set on the response.
 */
final class Context
{
    public ?Caller $caller = null;

    /** @var list<string> raw Set-Cookie values */
    public array $cookies = [];

    public function __construct(
        public Request $request,
        public readonly string $clientIp,
        public readonly bool $https,
    ) {
    }
}
