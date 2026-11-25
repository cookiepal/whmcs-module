<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The CookiePal agency API, over HTTPS with the host's `x-api-key`.
 *
 * Every attempt is written to the WHMCS Module Log, with the API key (and any one-time
 * sign-in link) passed as values to mask. The key itself is never put into the logged request.
 *
 * Timeouts: writes 30 seconds, so API Gateway's 29-second limit answers first; reads 20 seconds,
 * retried once when no answer arrives or the gateway answers 502, 503 or 504. A client for a
 * page render (forPage) waits 5 seconds and never retries, so a slow API can't hold a page up.
 * Writes are never retried.
 */
final class ApiClient
{
    public const DEFAULT_HOST = 'agency-api.cookiepal.io';
    public const READ_TIMEOUT = 20;
    public const WRITE_TIMEOUT = 30;
    public const PAGE_TIMEOUT = 5;

    private const RETRY_STATUSES = [502, 503, 504];

    private function __construct(
        private readonly string $host,
        private readonly string $apiKey,
        private readonly Transport $transport,
        private readonly string $action,
        private readonly string $userAgent,
        private readonly bool $forPage
    ) {
    }

    /**
     * The client for one server record: its hostname and password (the API key).
     *
     * @throws ApiException INVALID_HOST when the hostname isn't a cookiepal.io host
     */
    public static function forServer(
        string $hostname,
        string $apiKey,
        Transport $transport,
        string $action,
        string $userAgent,
        bool $forPage = false
    ): self {
        return new self(self::normaliseHost($hostname), trim($apiKey), $transport, $action, $userAgent, $forPage);
    }

    /**
     * `https://Agency-API.cookiepal.io/` and `agency-api.cookiepal.io` are the same host; empty means production.
     * Only cookiepal.io hosts are accepted, so a typo can't send the API key to someone else.
     *
     * @throws ApiException INVALID_HOST
     */
    public static function normaliseHost(string $raw): string
    {
        $host = strtolower(Hostname::strip($raw));
        if ($host === '') {
            return self::DEFAULT_HOST;
        }
        if (!Hostname::isValid($host) || !str_ends_with($host, '.cookiepal.io')) {
            throw new ApiException(
                0,
                'The server\'s hostname isn\'t a CookiePal API host. Use ' . self::DEFAULT_HOST . '.',
                ApiException::INVALID_HOST
            );
        }

        return $host;
    }

    /**
     * GET /external/customers: `{ accounts: [{ customerId, customerName, domains }] }`
     *
     * @return array<mixed>
     */
    public function listCustomers(): array
    {
        return $this->request('GET', '/external/customers');
    }

    /**
     * GET /external/customers/{c}: `{ customerId, customerName, domains }`
     *
     * @return array<mixed>
     */
    public function getCustomer(string $customerId): array
    {
        return $this->request('GET', '/external/customers/' . rawurlencode($customerId));
    }

    /**
     * POST /external/customer: 201 `{ customerId, customerName, customerEmail, domain: { domainId, handler, plan } }`,
     * or 200 with the same body when CookiePal replays an earlier call that had the same idempotency key.
     *
     * @return array<mixed>
     */
    public function createCustomer(
        string $customerName,
        string $customerEmail,
        string $domainId,
        string $plan,
        ?string $idempotencyKey = null
    ): array {
        return $this->request('POST', '/external/customer', self::withKey([
            'customerName' => $customerName,
            'customerEmail' => $customerEmail,
            'domainId' => $domainId,
            'plan' => $plan,
        ], $idempotencyKey));
    }

    /**
     * POST /external/domain: 201 `{ customerId, domain: { domainId, handler, plan } }`, or 200 with the same body
     * when CookiePal replays an earlier call that had the same idempotency key.
     *
     * @return array<mixed>
     */
    public function addDomain(string $customerId, string $domainId, string $plan, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/external/domain', self::withKey([
            'customerId' => $customerId,
            'domain' => ['domainId' => $domainId, 'plan' => $plan],
        ], $idempotencyKey));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function withKey(array $body, ?string $idempotencyKey): array
    {
        if ($idempotencyKey !== null) {
            $body['idempotencyKey'] = $idempotencyKey;
        }

        return $body;
    }

    /**
     * PUT /external/customers/{c}/domains/{d}/plan: `{ customerId, domainId, plan, changed }`
     *
     * @return array<mixed>
     */
    public function setPlan(string $customerId, string $domainId, string $plan): array
    {
        return $this->request('PUT', $this->domainPath($customerId, $domainId) . '/plan', ['plan' => $plan]);
    }

    /**
     * PUT /external/customers/{c}/domains/{d}/suspension: `{ customerId, domainId, suspended: true, changed }`
     *
     * @return array<mixed>
     */
    public function suspend(string $customerId, string $domainId): array
    {
        return $this->request('PUT', $this->domainPath($customerId, $domainId) . '/suspension');
    }

    /**
     * DELETE /external/customers/{c}/domains/{d}/suspension: `{ customerId, domainId, suspended: false, changed }`
     *
     * @return array<mixed>
     */
    public function unsuspend(string $customerId, string $domainId): array
    {
        return $this->request('DELETE', $this->domainPath($customerId, $domainId) . '/suspension');
    }

    /**
     * DELETE /external/domain/{c}/{d}: 204
     *
     * @return array<mixed>
     */
    public function deleteDomain(string $customerId, string $domainId): array
    {
        return $this->request(
            'DELETE',
            '/external/domain/' . rawurlencode($customerId) . '/' . rawurlencode($domainId)
        );
    }

    /**
     * DELETE /external/customer/{c}: 204
     *
     * @return array<mixed>
     */
    public function deleteCustomer(string $customerId): array
    {
        return $this->request('DELETE', '/external/customer/' . rawurlencode($customerId));
    }

    /**
     * POST /external/scan: 204
     *
     * @return array<mixed>
     */
    public function startScan(string $customerId, string $domainId): array
    {
        return $this->request('POST', '/external/scan', ['customerId' => $customerId, 'domainId' => $domainId]);
    }

    /**
     * POST /external/customers/{c}/sso: `{ url, expiresAt }`. The link works once, so it is masked in the log.
     *
     * @return array<mixed>
     */
    public function createSsoLink(string $customerId, string $domainId): array
    {
        return $this->request(
            'POST',
            '/external/customers/' . rawurlencode($customerId) . '/sso',
            ['domainId' => $domainId],
            ['url']
        );
    }

    /**
     * One API call. Returns the decoded JSON body, or [] for an empty 2xx body.
     *
     * @param non-empty-string $method
     * @param array<string, mixed>|null $body
     * @param list<string> $secretFields top-level answer fields to mask in the Module Log
     *
     * @return array<mixed>
     *
     * @throws ApiException
     */
    private function request(string $method, string $path, ?array $body = null, array $secretFields = []): array
    {
        if ($this->apiKey === '') {
            throw new ApiException(
                0,
                'No CookiePal API key: enter your agency API key in the server\'s Password field.',
                ApiException::NO_API_KEY
            );
        }

        $url = 'https://' . $this->host . $path;
        $json = $body === null
            ? null
            : json_encode((object) $body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $headers = [
            'x-api-key' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $isRead = $method === 'GET';
        $timeout = $this->forPage ? self::PAGE_TIMEOUT : ($isRead ? self::READ_TIMEOUT : self::WRITE_TIMEOUT);
        $attempts = $isRead && !$this->forPage ? 2 : 1;
        for ($attempt = 1;; $attempt++) {
            try {
                $response = $this->send($method, $url, $headers, $json, $timeout, $secretFields);
            } catch (ApiException $e) {
                if ($attempt < $attempts) {
                    continue;
                }
                throw $e;
            }
            if ($attempt < $attempts && in_array($response['status'], self::RETRY_STATUSES, true)) {
                continue;
            }

            return $this->decode($response['status'], $response['body']);
        }
    }

    /**
     * @param non-empty-string $method
     * @param array<string, string> $headers
     * @param list<string> $secretFields
     *
     * @return array{status: int, body: string}
     */
    private function send(
        string $method,
        string $url,
        array $headers,
        ?string $json,
        int $timeout,
        array $secretFields
    ): array {
        $logged = $method . ' ' . $url . ($json !== null ? "\n" . $json : '');
        try {
            $response = $this->transport->send($method, $url, $headers, $json, $timeout);
        } catch (ApiException $e) {
            Logger::call($this->action, $logged, $e->getMessage(), '', [$this->apiKey]);
            throw $e;
        }

        $decoded = json_decode($response['body'], true);
        Logger::call(
            $this->action,
            $logged,
            'HTTP ' . $response['status'] . "\n" . $response['body'],
            is_array($decoded) ? $decoded : '',
            array_merge([$this->apiKey], is_array($decoded) ? self::secretsIn($decoded, $secretFields) : [])
        );

        return $response;
    }

    /**
     * The values to mask for each secret field: the value as sent, JSON-escaped, and a one-time `code=` in it.
     *
     * @param array<mixed> $decoded
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private static function secretsIn(array $decoded, array $fields): array
    {
        $secrets = [];
        foreach ($fields as $field) {
            $value = $decoded[$field] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $secrets[] = $value;
            $secrets[] = substr((string) json_encode($value), 1, -1);
            if (preg_match('/[#?&]code=([^&#]+)/', $value, $match) === 1) {
                $secrets[] = $match[1];
            }
        }

        return array_values(array_unique($secrets));
    }

    /** @return array<mixed> */
    private function decode(int $status, string $raw): array
    {
        $decoded = trim($raw) === '' ? [] : json_decode($raw, true);

        if ($status >= 200 && $status < 300) {
            if (!is_array($decoded)) {
                throw new ApiException(
                    $status,
                    'CookiePal answered with something that isn\'t JSON.',
                    ApiException::BAD_RESPONSE
                );
            }

            return $decoded;
        }

        $message = null;
        $code = '';
        if (is_array($decoded)) {
            $message = $decoded['message'] ?? $decoded['Message'] ?? null;
            $code = is_string($decoded['code'] ?? null) ? $decoded['code'] : '';
        }
        if (!is_string($message) || trim($message) === '') {
            $message = match ($status) {
                401 => 'Unauthorized',
                403 => 'Forbidden',
                default => 'HTTP ' . $status,
            };
        }

        throw new ApiException($status, $message, $code);
    }

    private function domainPath(string $customerId, string $domainId): string
    {
        return '/external/customers/' . rawurlencode($customerId) . '/domains/' . rawurlencode($domainId);
    }
}
