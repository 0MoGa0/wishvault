<?php

declare(strict_types=1);

namespace WishVault\Upstream;

/**
 * Outgoing HTTP. Injected everywhere so tests can replace it.
 * Implementations throw HttpException on transport failures (DNS, timeout,
 * connection, response too large); HTTP error statuses come back as responses.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = [], HttpOptions $options = new HttpOptions()): HttpResponse;
}
