<?php

declare(strict_types=1);

namespace WishVault\Tests\Api;

use WishVault\Tests\TestCase;

final class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->standardUsers();
    }

    public function testMeAnonymous(): void
    {
        $body = self::body(self::send($this->app(), 'GET', '/api/v1/me'));
        self::assertSame(['kind' => 'anonymous', 'user' => null, 'can_wish' => true, 'unread_events' => 0], $body);
        $body = self::body(self::send($this->app(['ANON_ENABLED' => 'false']), 'GET', '/api/v1/me'));
        self::assertFalse($body['can_wish']);
    }

    public function testMeWithBearer(): void
    {
        $body = self::body(self::send($this->app(), 'GET', '/api/v1/me', headers: $this->bearer('a1')));
        self::assertSame('user', $body['kind']);
        self::assertSame(['id' => 31, 'username' => 'admin', 'role' => 3, 'is_admin' => true], $body['user']);
        self::assertTrue($body['can_wish']);

        $body = self::body(self::send($this->app(), 'GET', '/api/v1/me', headers: $this->bearer('u1')));
        self::assertFalse(self::assertIsArrayAndGet($body['user'], 'is_admin'));

        $body = self::body(self::send($this->app(), 'GET', '/api/v1/me', headers: $this->bearer('g0')));
        self::assertFalse($body['can_wish']);

        $body = self::body(self::send($this->app(['ADMIN_MIN_ROLE' => '1']), 'GET', '/api/v1/me', headers: $this->bearer('u1')));
        self::assertTrue(self::assertIsArrayAndGet($body['user'], 'is_admin'));
    }

    public function testInvalidBearerIs401AndUpstreamFailureIs502(): void
    {
        $res = self::send($this->app(), 'GET', '/api/v1/me', headers: $this->bearer('nope'));
        self::assertSame(401, $res->status);
        self::assertSame('unauthorized', self::errorCode($res));

        $res = self::send($this->app(), 'GET', '/api/v1/me', headers: ['Authorization' => 'Basic abc']);
        self::assertSame(401, $res->status);

        $this->http->status('#/api/users/me$#', 503);
        $res = self::send($this->app(), 'GET', '/api/v1/me', headers: $this->bearer('x'));
        self::assertSame(502, $res->status);
        self::assertSame('gamevault_unavailable', self::errorCode($res));
        self::assertStringNotContainsString('gamevault.example.com', $res->body);
    }

    public function testLoginCreatesSessionAndMovesAnonymousVotes(): void
    {
        $this->steamApp(620, 'Portal 2');
        $this->steamApp(400, 'Portal');
        $this->http->json('#/api/games\?#', ['data' => [], 'meta' => ['totalPages' => 1]]);
        $app = $this->app();
        // guest wishes two games, alice already voted for one of them
        $res = self::send($app, 'POST', '/api/v1/wishes', ['source' => 'steam', 'source_id' => '620']);
        self::assertSame(201, $res->status);
        $anon = self::cookieValue($res, 'wv_anon');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $anon);
        self::send($app, 'POST', '/api/v1/wishes', ['source' => 'steam', 'source_id' => '400'], cookies: ['wv_anon' => $anon]);
        self::send($app, 'PUT', '/api/v1/wishes/1/vote', headers: $this->bearer('u1'));

        $this->gvLogin('alice', 'pw', 'u1');
        $res = self::send($app, 'POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'pw'], cookies: ['wv_anon' => $anon]);
        self::assertSame(200, $res->status);
        $body = self::body($res);
        self::assertSame(['votes' => 1, 'wishes' => 2], $body['moved']);
        $me = $body['me'];
        self::assertIsArray($me);
        self::assertSame('alice', self::assertIsArrayAndGet($me['user'], 'username'));

        $session = self::cookie($res, 'wv_session');
        self::assertNotNull($session);
        self::assertStringContainsString('HttpOnly', $session);
        self::assertStringContainsString('SameSite=Lax', $session);
        self::assertStringContainsString('Max-Age=2592000', $session);
        self::assertStringNotContainsString('Secure', $session, 'plain http dev without PUBLIC_URL');
        self::assertStringContainsString('Max-Age=0', (string) self::cookie($res, 'wv_anon'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM voters'));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM votes WHERE user_id = 11'));

        $token = self::cookieValue($res, 'wv_session');
        $stored = $this->column('SELECT token_hash FROM sessions')[0] ?? null;
        self::assertSame(hash('sha256', $token), $stored);

        $me = self::body(self::send($app, 'GET', '/api/v1/me', cookies: ['wv_session' => $token]));
        self::assertSame('user', $me['kind']);
        $wish = self::body(self::send($app, 'GET', '/api/v1/wishes/2', cookies: ['wv_session' => $token]));
        self::assertTrue($wish['created_by_me']);
        self::assertTrue($wish['voted']);
    }

    public function testSessionCookieIsSecureBehindHttpsProxy(): void
    {
        $this->gvLogin('alice', 'pw', 'u1');
        $app = $this->app(['TRUSTED_PROXIES' => '10.0.0.0/8']);
        $res = self::send($app, 'POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'pw'], headers: ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.5'], remoteAddr: '10.1.2.3');
        self::assertStringContainsString('Secure', (string) self::cookie($res, 'wv_session'));

        $res = self::send($this->app(['PUBLIC_URL' => 'https://wishes.example.com']), 'POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'pw']);
        self::assertStringContainsString('Secure', (string) self::cookie($res, 'wv_session'));
    }

    public function testFailedLoginsLockTheIp(): void
    {
        $this->http->status('#/api/auth/basic/login$#', 401);
        $app = $this->app();
        for ($i = 0; $i < 5; $i++) {
            $res = self::send($app, 'POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'bad']);
            self::assertSame(401, $res->status);
            self::assertSame('login_failed', self::errorCode($res));
        }
        $res = self::send($app, 'POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'bad']);
        self::assertSame(429, $res->status);
        self::assertSame('login_locked', self::errorCode($res));
        self::assertSame('900', $res->headers['Retry-After']);
        self::assertSame(5, $this->http->count('#/api/auth/basic/login#'), 'locked attempts do not reach GameVault');

        // another IP is not affected
        $res = self::send($app, 'POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'bad'], remoteAddr: '192.0.2.99');
        self::assertSame(401, $res->status);

        $this->clock->advance('+901 seconds');
        $res = self::send($app, 'POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'bad']);
        self::assertSame(401, $res->status);
    }

    public function testLoginValidationAndRoleCheck(): void
    {
        $res = self::send($this->app(), 'POST', '/api/v1/auth/login', ['username' => '']);
        self::assertSame(400, $res->status);
        self::assertSame(['username' => 'required', 'password' => 'required'], self::assertIsArrayAndGet(self::body($res)['error'], 'details'));

        $this->gvLogin('guest', 'pw', 'g0');
        $res = self::send($this->app(), 'POST', '/api/v1/auth/login', ['username' => 'guest', 'password' => 'pw']);
        self::assertSame(403, $res->status);
        self::assertSame('role_too_low', self::errorCode($res));
        self::assertNull(self::cookie($res, 'wv_session'));

        $this->http->fail('#/api/auth/basic/login$#');
        $res = self::send($this->app(), 'POST', '/api/v1/auth/login', ['username' => 'a', 'password' => 'b']);
        self::assertSame(502, $res->status);
    }

    public function testLogoutAndExpiredSession(): void
    {
        $this->gvLogin('alice', 'pw', 'u1');
        $app = $this->app();
        $token = self::cookieValue(self::send($app, 'POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'pw']), 'wv_session');

        $this->clock->advance('+31 days');
        $res = self::send($app, 'GET', '/api/v1/me', cookies: ['wv_session' => $token]);
        self::assertSame('anonymous', self::body($res)['kind']);
        self::assertStringContainsString('Max-Age=0', (string) self::cookie($res, 'wv_session'));

        $this->clock->advance('-31 days');
        $res = self::send($app, 'POST', '/api/v1/auth/logout', cookies: ['wv_session' => $token]);
        self::assertSame(204, $res->status);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM sessions'));
        self::assertSame(204, self::send($app, 'POST', '/api/v1/auth/logout')->status);
    }

    public function testCsrfRules(): void
    {
        $app = $this->app(['PUBLIC_URL' => 'https://wishes.example.com']);
        $res = self::send($app, 'POST', '/api/v1/auth/logout', headers: ['X-Requested-With' => '']);
        self::assertSame(403, $res->status);
        self::assertSame('csrf_failed', self::errorCode($res));

        $res = self::send($app, 'POST', '/api/v1/auth/logout', headers: ['Origin' => 'https://evil.example.com']);
        self::assertSame('csrf_failed', self::errorCode($res));

        $res = self::send($app, 'POST', '/api/v1/auth/logout', headers: ['Origin' => 'https://wishes.example.com']);
        self::assertSame(204, $res->status);
        $res = self::send($app, 'POST', '/api/v1/auth/logout', headers: ['Origin' => 'http://localhost:5173', 'Host' => 'localhost:5173']);
        self::assertSame(204, $res->status);

        // Bearer requests are exempt
        $res = self::send($app, 'PUT', '/api/v1/wishes/1/vote', headers: $this->bearer('u1') + ['Origin' => 'http://tauri.localhost']);
        self::assertSame(404, $res->status);
    }

    public function testCorsOnEveryResponseWithoutCredentials(): void
    {
        $app = $this->app();
        foreach ([
            self::send($app, 'GET', '/api/v1/info', headers: ['Origin' => 'http://tauri.localhost']),
            self::send($app, 'GET', '/api/v1/me', headers: $this->bearer('nope')),
            self::send($app, 'GET', '/api/v1/nope'),
            self::send($app, 'POST', '/api/v1/auth/logout', headers: ['X-Requested-With' => '']),
            self::send($app, 'DELETE', '/api/v1/info'),
        ] as $res) {
            self::assertSame('*', $res->headers['Access-Control-Allow-Origin'] ?? null);
            self::assertSame('Retry-After', $res->headers['Access-Control-Expose-Headers'] ?? null);
            self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $res->headers);
        }
    }

    public function testPreflightForAnyApiPath(): void
    {
        foreach (['/api/v1/wishes/3/vote', '/api/v1/does/not/exist'] as $path) {
            $res = self::send($this->app(), 'OPTIONS', $path, headers: ['Origin' => 'http://tauri.localhost', 'Access-Control-Request-Method' => 'PUT']);
            self::assertSame(204, $res->status);
            self::assertSame('', $res->body);
            self::assertSame('*', $res->headers['Access-Control-Allow-Origin']);
            self::assertSame('GET, POST, PUT, PATCH, DELETE', $res->headers['Access-Control-Allow-Methods']);
            self::assertSame('Authorization, Content-Type, X-Requested-With', $res->headers['Access-Control-Allow-Headers']);
            self::assertSame('86400', $res->headers['Access-Control-Max-Age']);
            self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $res->headers);
        }
    }

    public function testSecurityHeaders(): void
    {
        $res = self::send($this->app(), 'GET', '/api/v1/me');
        self::assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        self::assertSame('DENY', $res->headers['X-Frame-Options']);
        self::assertSame("default-src 'none'; frame-ancestors 'none'", $res->headers['Content-Security-Policy']);
        self::assertSame('no-store', $res->headers['Cache-Control']);
    }

    public function testMalformedJsonBody(): void
    {
        $res = $this->app()->handle(new \WishVault\Http\Request('POST', '/api/v1/auth/login', headers: ['x-requested-with' => 'WishVault'], body: '{nope'));
        self::assertSame(400, $res->status);
        self::assertSame('validation_failed', self::errorCode($res));
    }

    private static function assertIsArrayAndGet(mixed $value, ?string $key = null): mixed
    {
        self::assertIsArray($value);
        return $key === null ? $value : ($value[$key] ?? null);
    }
}
