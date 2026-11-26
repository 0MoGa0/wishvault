<?php

declare(strict_types=1);

namespace WishVault\Http;

/**
 * Minimal router: exact segments and `{name}` placeholders (digits only).
 */
final class Router
{
    /** @var list<array{string, string, \Closure(Request): Response}> */
    private array $routes = [];

    /**
     * @param \Closure(Request): Response $handler
     */
    public function add(string $method, string $pattern, \Closure $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[1-9][0-9]{0,17})', $pattern) . '$#';
        $this->routes[] = [strtoupper($method), $regex, $handler];
    }

    public function dispatch(Request $request): Response
    {
        $methodAllowed = [];
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (preg_match($regex, $request->path, $m) !== 1) {
                continue;
            }
            if ($method !== $request->method) {
                $methodAllowed[] = $method;
                continue;
            }
            /** @var array<string, string> $params */
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($request->withRouteParams($params));
        }
        if ($methodAllowed !== []) {
            return Response::error(new ApiError(405, 'not_found', 'Method not allowed'))
                ->withHeader('Allow', implode(', ', array_unique($methodAllowed)));
        }
        return Response::error(ApiError::notFound());
    }
}
