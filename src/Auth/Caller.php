<?php

declare(strict_types=1);

namespace WishVault\Auth;

/**
 * Who is calling. `via` is how the caller was identified:
 * `bearer`, `session`, `anon` (anonymous cookie) or `none`.
 */
final readonly class Caller
{
    private function __construct(
        public string $via,
        public ?int $userId,
        public ?string $username,
        public ?int $role,
        public ?int $voterId,
        public bool $isAdmin,
        public bool $canWish,
    ) {
    }

    public static function user(string $via, int $id, string $username, int $role, int $wishMinRole, int $adminMinRole): self
    {
        $canWish = $role >= $wishMinRole;
        return new self($via, $id, $username, $role, null, $canWish && $role >= $adminMinRole, $canWish);
    }

    public static function anonymous(?int $voterId, bool $anonEnabled): self
    {
        return new self($voterId === null ? 'none' : 'anon', null, null, null, $voterId, false, $anonEnabled);
    }

    public function withVoter(int $voterId): self
    {
        return new self('anon', $this->userId, $this->username, $this->role, $voterId, $this->isAdmin, $this->canWish);
    }

    public function isAccount(): bool
    {
        return $this->userId !== null;
    }

    /** Accounts with at least wish_min_role see status, reason and names. */
    public function seesDetails(): bool
    {
        return $this->isAccount() && $this->canWish;
    }
}
