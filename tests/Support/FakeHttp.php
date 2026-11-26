<?php

declare(strict_types=1);

namespace WishVault\Tests\Support;

use WishVault\Upstream\HttpClient;
use WishVault\Upstream\HttpException;
use WishVault\Upstream\HttpOptions;
use WishVault\Upstream\HttpResponse;

/**
 * Scripted HTTP client. Routes are URL regexes; the newest matching route
 * wins. A handler may return null to fall through to older routes.
 * Unmatched requests fail like a network error and are recorded.
 */
final class FakeHttp implements HttpClient
{
    /** @var list<array{string, \Closure(string, array<string, string>): ?HttpResponse}> */
    private array $routes = [];

    /** @var list<array{url: string, headers: array<string, string>, options: HttpOptions}> */
    public array $requests = [];

    /** @var list<string> */
    public array $unmatched = [];

    /**
     * @param HttpResponse|\Closure(string, array<string, string>): ?HttpResponse $response
     */
    public function on(string $pattern, HttpResponse|\Closure $response): self
    {
        $this->routes[] = [$pattern, $response instanceof HttpResponse ? static fn (): HttpResponse => $response : $response];
        return $this;
    }

    public function json(string $pattern, mixed $data, int $status = 200): self
    {
        return $this->on($pattern, new HttpResponse($status, (string) json_encode($data)));
    }

    public function status(string $pattern, int $status): self
    {
        return $this->on($pattern, new HttpResponse($status, ''));
    }

    public function fail(string $pattern): self
    {
        return $this->on($pattern, static fn (): HttpResponse => throw new HttpException('down'));
    }

    public function get(string $url, array $headers = [], HttpOptions $options = new HttpOptions()): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'options' => $options];
        foreach (array_reverse($this->routes) as [$pattern, $handler]) {
            if (preg_match($pattern, $url) === 1) {
                $response = $handler($url, $headers);
                if ($response !== null) {
                    return $response;
                }
            }
        }
        $this->unmatched[] = $url;
        throw new HttpException('No fake route for ' . $url);
    }

    /** @phpstan-impure */
    public function count(string $pattern): int
    {
        return count(array_filter($this->requests, static fn (array $r): bool => preg_match($pattern, $r['url']) === 1));
    }
}
