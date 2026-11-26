<?php

declare(strict_types=1);

namespace WishVault\Upstream;

use WishVault\Config;

final class CurlHttpClient implements HttpClient
{
    public function get(string $url, array $headers = [], HttpOptions $options = new HttpOptions()): HttpResponse
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($url === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            throw new HttpException('Unsupported URL scheme');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $body = '';
        $tooLarge = false;
        $max = $options->maxBytes;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => $options->connectTimeout,
            CURLOPT_TIMEOUT => $options->timeout,
            CURLOPT_FOLLOWLOCATION => $options->followRedirects,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'WishVault/' . Config::VERSION,
            CURLOPT_WRITEFUNCTION => static function (mixed $handle, string $chunk) use (&$body, &$tooLarge, $max): int {
                if (strlen($body) + strlen($chunk) > $max) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        unset($ch);
        if ($tooLarge) {
            throw new HttpException('Response too large');
        }
        if ($ok === false || $errno !== 0 || $status === 0) {
            throw new HttpException('Request failed (curl error ' . $errno . ')');
        }
        return new HttpResponse($status, $body);
    }
}
