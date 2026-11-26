<?php

declare(strict_types=1);

namespace WishVault;

use WishVault\Api\AdminController;
use WishVault\Api\AuthController;
use WishVault\Api\EventController;
use WishVault\Api\InfoController;
use WishVault\Api\SearchController;
use WishVault\Api\WishController;
use WishVault\Auth\Auth;
use WishVault\Db\Cache;
use WishVault\Db\JobsState;
use WishVault\Events\Events;
use WishVault\Http\ApiError;
use WishVault\Http\Context;
use WishVault\Http\Request;
use WishVault\Http\Response;
use WishVault\Http\Router;
use WishVault\Library\Matcher;
use WishVault\Security\RateLimiter;
use WishVault\Security\TrustedProxies;
use WishVault\Upstream\CurlHttpClient;
use WishVault\Upstream\GameVault;
use WishVault\Upstream\HttpClient;
use WishVault\Upstream\Steam;
use WishVault\Wishes\Search;
use WishVault\Wishes\Wishes;

/**
 * Wires routes to controllers and applies the cross-cutting rules:
 * CSRF for cookie requests, CORS (without credentials) for every response,
 * error codes only, security headers.
 */
final class App
{
    public const string API_PREFIX = '/api/v1';
    public const string CSRF_HEADER_VALUE = 'WishVault';

    private readonly HttpClient $http;
    private readonly Clock $clock;
    private readonly TrustedProxies $proxies;

    public function __construct(
        private readonly Config $config,
        private ?\PDO $pdo = null,
        ?HttpClient $http = null,
        ?Clock $clock = null,
    ) {
        $this->http = $http ?? new CurlHttpClient();
        $this->clock = $clock ?? new SystemClock();
        $this->proxies = new TrustedProxies($config->trustedProxies);
    }

    public function handle(Request $request): Response
    {
        $ctx = new Context($request, $this->proxies->clientIp($request), $this->proxies->isHttps($request));
        try {
            if ($request->method === 'OPTIONS') {
                $response = self::preflight();
            } else {
                $response = $this->router($ctx)->dispatch($request);
            }
        } catch (ApiError $e) {
            $response = Response::error($e);
        } catch (\Throwable $e) {
            error_log('wishvault: ' . $e::class . ' in ' . $e->getFile() . ':' . $e->getLine());
            $response = Response::error(new ApiError(500, 'internal_error', 'Internal error'));
        }
        foreach ($ctx->cookies as $cookie) {
            $response = $response->withCookie($cookie);
        }
        return self::withCors(self::withSecurityHeaders($response));
    }

    public function db(): \PDO
    {
        return $this->pdo ??= Db\Database::open($this->config->databasePath());
    }

    /**
     * Cookie-authenticated (i.e. every non-Bearer) write request needs the
     * X-Requested-With header and, if sent, a same-origin Origin.
     */
    private function checkCsrf(Context $ctx): void
    {
        $request = $ctx->request;
        if (!$request->isWrite() || $request->header('authorization') !== null) {
            return;
        }
        if ($request->header('x-requested-with') !== self::CSRF_HEADER_VALUE) {
            throw ApiError::forbidden('csrf_failed', 'Missing X-Requested-With header');
        }
        $origin = $request->header('origin');
        if ($origin !== null && !in_array(strtolower(rtrim($origin, '/')), $this->allowedOrigins($ctx), true)) {
            throw ApiError::forbidden('csrf_failed', 'Cross-origin request');
        }
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(Context $ctx): array
    {
        $origins = [];
        $host = $ctx->request->header('host');
        if ($host !== null && $host !== '') {
            $origins[] = strtolower(($ctx->https ? 'https' : 'http') . '://' . $host);
        }
        $public = $this->config->publicUrl;
        if ($public !== null) {
            $parts = parse_url($public);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origins[] = strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
            }
        }
        return $origins;
    }

    private function router(Context $ctx): Router
    {
        $db = $this->db();
        $cache = new Cache($db, $this->clock);
        $limiter = new RateLimiter($db, $this->clock);
        $gameVault = new GameVault($this->config, $this->http, $cache, $this->clock);
        $steam = new Steam($this->config, $this->http, $cache);
        $events = new Events($db, $this->clock);
        $auth = new Auth($this->config, $db, $gameVault, $limiter, $this->clock);
        $wishes = new Wishes($this->config, $db, $steam, $gameVault, $cache, new Matcher($db), $events, $limiter, $this->clock);

        $info = new InfoController($this->config, $this->db(...));
        $authC = new AuthController($auth, $events);
        $searchC = new SearchController($auth, new Search($this->config, $steam, $gameVault, $wishes, $limiter, $this->clock));
        $wishC = new WishController($auth, $wishes);
        $eventC = new EventController($auth, $events);
        $adminC = new AdminController($auth, $db, new JobsState($db, $this->clock), $this->clock, $this->config);

        $router = new Router();
        $p = self::API_PREFIX;
        /** @var list<array{string, string, \Closure(Context): Response}> $routes */
        $routes = [
            ['GET', '/info', static fn (Context $c): Response => $info->info($c->request)],
            ['GET', '/me', $authC->me(...)],
            ['POST', '/auth/login', $authC->login(...)],
            ['POST', '/auth/logout', $authC->logout(...)],
            ['GET', '/search', $searchC->search(...)],
            ['GET', '/wishes', $wishC->list(...)],
            ['POST', '/wishes', $wishC->create(...)],
            ['GET', '/wishes/{id}', $wishC->get(...)],
            ['PATCH', '/wishes/{id}', $wishC->update(...)],
            ['DELETE', '/wishes/{id}', $wishC->delete(...)],
            ['PUT', '/wishes/{id}/vote', $wishC->vote(...)],
            ['DELETE', '/wishes/{id}/vote', $wishC->unvote(...)],
            ['GET', '/wishes/{id}/tags', $wishC->tags(...)],
            ['GET', '/events', $eventC->list(...)],
            ['POST', '/events/read', $eventC->read(...)],
            ['POST', '/admin/sync', $adminC->sync(...)],
            ['GET', '/admin/status', $adminC->status(...)],
        ];
        $csrf = $this->checkCsrf(...);
        foreach ($routes as [$method, $path, $handler]) {
            $router->add($method, $p . $path, static function (Request $r) use ($handler, $ctx, $csrf): Response {
                $csrf($ctx);
                // the dispatched request carries the route params
                $ctx->request = $r;
                return $handler($ctx);
            });
        }
        return $router;
    }

    private static function preflight(): Response
    {
        return new Response(204, '', [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Requested-With',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    /**
     * Every API response, errors included, may be read from any origin.
     * Never with credentials: foreign origins authenticate with Bearer only.
     */
    private static function withCors(Response $response): Response
    {
        $response->headers['Access-Control-Allow-Origin'] = '*';
        $response->headers['Access-Control-Expose-Headers'] = 'Retry-After';
        return $response;
    }

    private static function withSecurityHeaders(Response $response): Response
    {
        $response->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cache-Control' => 'no-store',
        ];
        return $response;
    }
}
