<?php

declare(strict_types=1);

namespace WishVault;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
