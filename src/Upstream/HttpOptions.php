<?php

declare(strict_types=1);

namespace WishVault\Upstream;

final readonly class HttpOptions
{
    public function __construct(
        public int $timeout = 15,
        public int $connectTimeout = 5,
        public bool $followRedirects = false,
        public int $maxBytes = 8 * 1024 * 1024,
    ) {
    }
}
