<?php

declare(strict_types=1);

namespace WishVault\Upstream;

final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function notFound(): bool
    {
        return $this->status === 404;
    }

    public function rateLimited(): bool
    {
        return $this->status === 429;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    /** Decoded JSON body, null if it is not valid JSON. */
    public function json(): mixed
    {
        try {
            return json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
