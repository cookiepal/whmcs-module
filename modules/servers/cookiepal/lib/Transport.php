<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Sends one HTTP request. The module uses CurlTransport; the tests inject a fake.
 */
interface Transport
{
    /**
     * @param non-empty-string $method
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}
     *
     * @throws ApiException with status 0 and code NETWORK_ERROR when no answer arrives
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array;
}
