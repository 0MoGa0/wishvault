<?php

declare(strict_types=1);

namespace WishVault;

use WishVault\Api\InfoController;
use WishVault\Http\ApiError;
use WishVault\Http\Request;
use WishVault\Http\Response;
use WishVault\Http\Router;

/**
 * Wires routes to controllers. Endpoints that are not built yet answer
 * `501 not_implemented` so clients can already integrate against the contract.
 */
final class App
{
    public const string API_PREFIX = '/api/v1';

    public function __construct(
        private readonly Config $config,
        private ?\PDO $pdo = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->router()->dispatch($request);
        } catch (ApiError $e) {
            $response = Response::error($e);
        } catch (\Throwable $e) {
            error_log('wishvault: ' . $e::class . ': ' . $e->getMessage());
            $response = Response::error(new ApiError(500, 'internal_error', 'Internal error'));
        }
        return self::withSecurityHeaders($response);
    }

    public function db(): \PDO
    {
        return $this->pdo ??= Db\Database::open($this->config->databasePath());
    }

    private function router(): Router
    {
        $router = new Router();
        $p = self::API_PREFIX;
        $info = new InfoController($this->config, $this->db(...));
        $router->add('GET', "$p/info", $info->info(...));

        $todo = static fn (Request $r): Response => throw ApiError::notImplemented();
        foreach ([
            ['GET', '/me'],
            ['POST', '/auth/login'],
            ['POST', '/auth/logout'],
            ['GET', '/search'],
            ['GET', '/wishes'],
            ['POST', '/wishes'],
            ['GET', '/wishes/{id}'],
            ['PATCH', '/wishes/{id}'],
            ['DELETE', '/wishes/{id}'],
            ['PUT', '/wishes/{id}/vote'],
            ['DELETE', '/wishes/{id}/vote'],
            ['GET', '/wishes/{id}/tags'],
            ['GET', '/events'],
            ['POST', '/events/read'],
            ['POST', '/admin/sync'],
            ['GET', '/admin/status'],
        ] as [$method, $path]) {
            $router->add($method, $p . $path, $todo);
        }
        return $router;
    }

    private static function withSecurityHeaders(Response $response): Response
    {
        $response->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ];
        return $response;
    }
}
