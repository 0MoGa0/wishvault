<?php

declare(strict_types=1);

namespace WishVault\Http;

/**
 * An error that maps to the `Error` schema of openapi.yaml.
 * `errorCode` must be one of `ErrorCode` in the contract.
 */
final class ApiError extends \RuntimeException
{
    /**
     * @param array<string, string> $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, string> $details field => required|invalid|too_short|too_long
     */
    public static function validation(array $details): self
    {
        return new self(400, 'validation_failed', 'Request validation failed', $details);
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $code = 'forbidden', string $message = 'Not allowed'): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $code = 'not_found', string $message = 'Not found'): self
    {
        return new self(404, $code, $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function rateLimited(int $retryAfter, string $code = 'rate_limited'): self
    {
        return new self(429, $code, 'Too many requests', [], $retryAfter);
    }

    public static function upstream(string $code, string $message): self
    {
        return new self(502, $code, $message);
    }

    public static function notImplemented(): self
    {
        return new self(501, 'not_implemented', 'Not implemented yet');
    }
}
