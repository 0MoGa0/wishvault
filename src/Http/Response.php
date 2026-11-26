<?php

declare(strict_types=1);

namespace WishVault\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $cookies raw Set-Cookie values
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public array $headers = [],
        public array $cookies = [],
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public static function error(ApiError $error): self
    {
        $payload = ['code' => $error->errorCode, 'message' => $error->getMessage()];
        if ($error->details !== []) {
            $payload['details'] = $error->details;
        }
        $response = self::json(['error' => $payload], $error->status);
        if ($error->retryAfter !== null) {
            $response->headers['Retry-After'] = (string) $error->retryAfter;
        }
        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withCookie(string $setCookie): self
    {
        $clone = clone $this;
        $clone->cookies[] = $setCookie;
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        echo $this->body;
    }
}
