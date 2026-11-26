<?php

declare(strict_types=1);

namespace WishVault\Auth;

use WishVault\Clock;
use WishVault\Config;
use WishVault\Db\Database;
use WishVault\Http\ApiError;
use WishVault\Http\Context;
use WishVault\Security\RateLimiter;
use WishVault\Support\Data;
use WishVault\SystemClock;
use WishVault\Upstream\GameVault;

/**
 * Resolves the caller (Bearer → session cookie → anonymous cookie), issues
 * anonymous tokens and handles the web login. Tokens are stored as SHA-256
 * only; GameVault tokens and passwords are never stored.
 */
final readonly class Auth
{
    public const string SESSION_COOKIE = 'wv_session';
    public const string ANON_COOKIE = 'wv_anon';
    public const int SESSION_DAYS = 30;
    public const int ANON_DAYS = 365;
    public const int LOGIN_MAX_FAILS = 5;
    public const int LOGIN_WINDOW = 900;

    public function __construct(
        private Config $config,
        private \PDO $pdo,
        private GameVault $gameVault,
        private RateLimiter $limiter,
        private Clock $clock,
    ) {
    }

    public function caller(Context $ctx): Caller
    {
        return $ctx->caller ??= $this->resolve($ctx);
    }

    private function resolve(Context $ctx): Caller
    {
        $request = $ctx->request;
        if ($request->header('authorization') !== null) {
            $token = $request->bearerToken();
            if ($token === null) {
                throw ApiError::unauthorized('Unsupported authorization header');
            }
            $user = $this->gameVault->userForToken($token);
            $this->rememberUser($user);
            return $this->callerForUser('bearer', $user['id'], $user['username'], $user['role']);
        }

        $session = $request->cookies[self::SESSION_COOKIE] ?? null;
        if ($session !== null) {
            $row = Database::run(
                $this->pdo,
                'SELECT u.id, u.username, u.role, s.last_seen_at FROM sessions s JOIN users u ON u.id = s.user_id
                 WHERE s.token_hash = :h AND s.expires_at > :now',
                ['h' => hash('sha256', $session), 'now' => $this->now()],
            )->fetch();
            $id = Data::int($row, 'id');
            $username = Data::str($row, 'username');
            $role = Data::int($row, 'role');
            if ($id !== null && $username !== null && $role !== null) {
                $this->touchSession($session, Data::str($row, 'last_seen_at'));
                return $this->callerForUser('session', $id, $username, $role);
            }
            // expired or unknown: drop the cookie and continue as guest
            $ctx->cookies[] = $this->cookie($ctx, self::SESSION_COOKIE, '', -1);
        }

        $anon = $request->cookies[self::ANON_COOKIE] ?? null;
        if ($anon !== null) {
            $voterId = $this->voterId($anon);
            if ($voterId !== null) {
                return Caller::anonymous($voterId, $this->config->anonEnabled);
            }
            $ctx->cookies[] = $this->cookie($ctx, self::ANON_COOKIE, '', -1);
        }
        return Caller::anonymous(null, $this->config->anonEnabled);
    }

    /**
     * Caller that may write (wish, vote). Anonymous callers get a voter
     * (and the `wv_anon` cookie) on their first write.
     */
    public function writer(Context $ctx): Caller
    {
        $caller = $this->caller($ctx);
        if ($caller->isAccount()) {
            if (!$caller->canWish) {
                throw ApiError::forbidden('role_too_low', 'Role below wish_min_role');
            }
            return $caller;
        }
        if (!$this->config->anonEnabled) {
            throw ApiError::forbidden('anonymous_disabled', 'Anonymous participation is disabled');
        }
        if ($caller->voterId !== null) {
            $this->touchVoter($caller->voterId);
            return $caller;
        }
        $token = bin2hex(random_bytes(16));
        $now = $this->now();
        Database::run(
            $this->pdo,
            'INSERT INTO voters (token_hash, created_at, last_seen_at) VALUES (:h, :t, :t)',
            ['h' => hash('sha256', $token), 't' => $now],
        );
        $ctx->cookies[] = $this->cookie($ctx, self::ANON_COOKIE, $token, self::ANON_DAYS * 86400);
        return $ctx->caller = $caller->withVoter((int) $this->pdo->lastInsertId());
    }

    /** Account (any role ≥ wish_min_role) or 401/403. */
    public function account(Context $ctx): Caller
    {
        $caller = $this->caller($ctx);
        if (!$caller->isAccount()) {
            throw ApiError::unauthorized();
        }
        if (!$caller->canWish) {
            throw ApiError::forbidden('role_too_low', 'Role below wish_min_role');
        }
        return $caller;
    }

    public function admin(Context $ctx): Caller
    {
        $caller = $this->account($ctx);
        if (!$caller->isAdmin) {
            throw ApiError::forbidden('forbidden', 'Admin role required');
        }
        return $caller;
    }

    /**
     * Web login. Moves the anonymous token's votes and wishes to the account.
     *
     * @return array{caller: Caller, moved: array{votes: int, wishes: int}}
     */
    public function login(Context $ctx, string $username, string $password): array
    {
        $bucket = 'login:' . $ctx->clientIp;
        $this->limiter->check($bucket, self::LOGIN_MAX_FAILS, self::LOGIN_WINDOW, 'login_locked');
        try {
            $user = $this->gameVault->login($username, $password);
        } catch (ApiError $e) {
            if ($e->errorCode === 'login_failed') {
                $this->limiter->hit($bucket);
            }
            throw $e;
        }
        if ($user['role'] < $this->config->wishMinRole) {
            throw ApiError::forbidden('role_too_low', 'Role below wish_min_role');
        }
        $this->limiter->clear($bucket);
        $this->rememberUser($user);

        $moved = ['votes' => 0, 'wishes' => 0];
        $anon = $ctx->request->cookies[self::ANON_COOKIE] ?? null;
        $voterId = $anon === null ? null : $this->voterId($anon);
        if ($voterId !== null) {
            $moved = $this->moveVoter($voterId, $user['id']);
        }
        if ($anon !== null) {
            $ctx->cookies[] = $this->cookie($ctx, self::ANON_COOKIE, '', -1);
        }

        $old = $ctx->request->cookies[self::SESSION_COOKIE] ?? null;
        if ($old !== null) {
            Database::run($this->pdo, 'DELETE FROM sessions WHERE token_hash = :h', ['h' => hash('sha256', $old)]);
        }
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        Database::run(
            $this->pdo,
            'INSERT INTO sessions (token_hash, user_id, created_at, expires_at, last_seen_at) VALUES (:h, :u, :c, :e, :c)',
            [
                'h' => hash('sha256', $token),
                'u' => $user['id'],
                'c' => SystemClock::format($now),
                'e' => SystemClock::format($now->modify('+' . self::SESSION_DAYS . ' days')),
            ],
        );
        $ctx->cookies[] = $this->cookie($ctx, self::SESSION_COOKIE, $token, self::SESSION_DAYS * 86400);
        $ctx->caller = $this->callerForUser('session', $user['id'], $user['username'], $user['role']);
        return ['caller' => $ctx->caller, 'moved' => $moved];
    }

    public function logout(Context $ctx): void
    {
        $session = $ctx->request->cookies[self::SESSION_COOKIE] ?? null;
        if ($session !== null) {
            Database::run($this->pdo, 'DELETE FROM sessions WHERE token_hash = :h', ['h' => hash('sha256', $session)]);
            $ctx->cookies[] = $this->cookie($ctx, self::SESSION_COOKIE, '', -1);
        }
        $ctx->caller = null;
    }

    /**
     * @return array{votes: int, wishes: int}
     */
    private function moveVoter(int $voterId, int $userId): array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            // duplicate votes (the account already voted) are dropped
            Database::run(
                $this->pdo,
                'DELETE FROM votes WHERE voter_id = :v
                 AND wish_id IN (SELECT wish_id FROM votes WHERE user_id = :u)',
                ['v' => $voterId, 'u' => $userId],
            );
            $votes = Database::run(
                $this->pdo,
                'UPDATE votes SET user_id = :u, voter_id = NULL WHERE voter_id = :v',
                ['u' => $userId, 'v' => $voterId],
            )->rowCount();
            $wishes = Database::run(
                $this->pdo,
                'UPDATE wishes SET created_by_user = COALESCE(created_by_user, :u), created_by_voter = NULL
                 WHERE created_by_voter = :v',
                ['u' => $userId, 'v' => $voterId],
            )->rowCount();
            Database::run($this->pdo, 'DELETE FROM voters WHERE id = :v', ['v' => $voterId]);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
        return ['votes' => $votes, 'wishes' => $wishes];
    }

    private function callerForUser(string $via, int $id, string $username, int $role): Caller
    {
        return Caller::user($via, $id, $username, $role, $this->config->wishMinRole, $this->config->adminMinRole);
    }

    /**
     * @param array{id: int, username: string, role: int} $user
     */
    private function rememberUser(array $user): void
    {
        $now = $this->now();
        Database::run(
            $this->pdo,
            'INSERT INTO users (id, username, role, first_seen_at, last_seen_at) VALUES (:id, :n, :r, :t, :t)
             ON CONFLICT(id) DO UPDATE SET username = excluded.username, role = excluded.role, last_seen_at = excluded.last_seen_at',
            ['id' => $user['id'], 'n' => $user['username'], 'r' => $user['role'], 't' => $now],
        );
    }

    private function voterId(string $token): ?int
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        $id = Database::run(
            $this->pdo,
            'SELECT id FROM voters WHERE token_hash = :h',
            ['h' => hash('sha256', $token)],
        )->fetchColumn();
        return is_numeric($id) ? (int) $id : null;
    }

    private function touchVoter(int $voterId): void
    {
        Database::run($this->pdo, 'UPDATE voters SET last_seen_at = :t WHERE id = :id', ['t' => $this->now(), 'id' => $voterId]);
    }

    private function touchSession(string $token, ?string $lastSeen): void
    {
        $hourAgo = SystemClock::format($this->clock->now()->modify('-1 hour'));
        if ($lastSeen !== null && $lastSeen > $hourAgo) {
            return;
        }
        Database::run(
            $this->pdo,
            'UPDATE sessions SET last_seen_at = :t WHERE token_hash = :h',
            ['t' => $this->now(), 'h' => hash('sha256', $token)],
        );
    }

    private function cookie(Context $ctx, string $name, string $value, int $maxAge): string
    {
        $parts = [$name . '=' . $value, 'Path=/', 'HttpOnly', 'SameSite=Lax'];
        $parts[] = $maxAge < 0 ? 'Max-Age=0' : 'Max-Age=' . $maxAge;
        if ($maxAge < 0) {
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }
        if ($this->secureCookies($ctx)) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    /** Secure unless the service clearly runs on plain HTTP (local development). */
    private function secureCookies(Context $ctx): bool
    {
        if ($ctx->https) {
            return true;
        }
        $public = $this->config->publicUrl;
        return $public !== null && str_starts_with(strtolower($public), 'https://');
    }

    private function now(): string
    {
        return SystemClock::format($this->clock->now());
    }
}
