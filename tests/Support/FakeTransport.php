<?php

declare(strict_types=1);

namespace CookiePal\Tests\Support;

use WHMCS\Module\Server\CookiePal\ApiException;
use WHMCS\Module\Server\CookiePal\Transport;

/**
 * Answers requests from a queue of expectations, in order, and records what was sent.
 * A request that doesn't match the next expectation fails the test.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, path: string, status: int, body: string, networkError: bool}> */
    private array $queue = [];

    /**
     * @var list<array{method: string, url: string, path: string, headers: array<string, string>, body: ?string,
     *     json: mixed, timeout: int}>
     */
    public array $requests = [];

    /** @var list<string> */
    public array $mismatches = [];

    /** @param array<mixed>|string|null $body an array is sent as JSON */
    public function expect(string $method, string $path, int $status, array|string|null $body = null): self
    {
        $this->queue[] = [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'body' => is_array($body) ? (string) json_encode($body, JSON_UNESCAPED_SLASHES) : (string) $body,
            'networkError' => false,
        ];

        return $this;
    }

    public function expectNetworkError(string $method, string $path): self
    {
        $this->queue[] = ['method' => $method, 'path' => $path, 'status' => 0, 'body' => '', 'networkError' => true];

        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'path' => $path,
            'headers' => $headers,
            'body' => $body,
            'json' => $body === null ? null : json_decode($body, true),
            'timeout' => $timeoutSeconds,
        ];

        $next = array_shift($this->queue);
        if ($next === null || $next['method'] !== $method || $next['path'] !== $path) {
            $expected = $next === null ? 'nothing' : $next['method'] . ' ' . $next['path'];
            $mismatch = "Sent {$method} {$path}, expected {$expected}";
            $this->mismatches[] = $mismatch;

            throw new \LogicException($mismatch);
        }
        if ($next['networkError']) {
            throw new ApiException(
                0,
                'Couldn\'t reach CookiePal (curl error 28): Operation timed out',
                ApiException::NETWORK_ERROR
            );
        }

        return ['status' => $next['status'], 'body' => $next['body']];
    }

    public function pending(): int
    {
        return count($this->queue);
    }

    /** @return list<string> "METHOD /path" for each request sent */
    public function sent(): array
    {
        return array_map(fn (array $r): string => $r['method'] . ' ' . $r['path'], $this->requests);
    }
}
