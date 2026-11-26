<?php

declare(strict_types=1);

namespace WishVault\Upstream;

/** Transport failure (no HTTP response). Messages never contain credentials. */
final class HttpException extends \RuntimeException
{
}
