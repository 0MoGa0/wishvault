<?php

declare(strict_types=1);

namespace WishVault\Tests\Unit;

use WishVault\Http\ApiError;
use WishVault\Security\RateLimiter;
use WishVault\Tests\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testSlidingWindowWithRetryAfter(): void
    {
        $limiter = new RateLimiter($this->pdo, $this->clock);
        $limiter->attempt('b', 2, 60);
        $this->clock->advance('+10 seconds');
        $limiter->attempt('b', 2, 60);
        try {
            $limiter->attempt('b', 2, 60);
            self::fail('expected 429');
        } catch (ApiError $e) {
            self::assertSame(429, $e->status);
            self::assertSame('rate_limited', $e->errorCode);
            self::assertSame(50, $e->retryAfter);
        }
        $this->clock->advance('+51 seconds');
        $limiter->attempt('b', 2, 60);
        $limiter->attempt('other', 1, 60);

        $this->clock->advance('+2 days');
        self::assertSame(4, $limiter->purge());
    }

    public function testZeroLimitAlwaysBlocks(): void
    {
        $this->expectException(ApiError::class);
        (new RateLimiter($this->pdo, $this->clock))->check('x', 0, 3600);
    }
}
