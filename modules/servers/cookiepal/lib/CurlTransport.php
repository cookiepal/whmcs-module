<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The real transport: PHP's curl extension, HTTPS only, certificates verified, no redirects.
 */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException(
                0,
                'The PHP curl extension isn\'t enabled on this WHMCS server.',
                ApiException::NETWORK_ERROR
            );
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiException(0, 'Couldn\'t start a curl request.', ApiException::NETWORK_ERROR);
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if (!curl_setopt_array($handle, $options)) {
            throw new ApiException(
                0,
                'Couldn\'t set up the curl request: ' . curl_error($handle),
                ApiException::NETWORK_ERROR
            );
        }

        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            throw new ApiException(
                0,
                'Couldn\'t reach CookiePal (curl error ' . curl_errno($handle) . '): ' . curl_error($handle),
                ApiException::NETWORK_ERROR
            );
        }

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $raw];
    }
}
