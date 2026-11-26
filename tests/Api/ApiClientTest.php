<?php

declare(strict_types=1);

namespace CookiePal\Tests\Api;

use CookiePal\Tests\Support\ApiFixtures;
use CookiePal\Tests\Support\FakeTransport;
use CookiePal\Tests\Support\ModuleLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\CookiePal\ApiClient;
use WHMCS\Module\Server\CookiePal\ApiException;

final class ApiClientTest extends TestCase
{
    use ApiFixtures;

    private const USER_AGENT = 'CookiePal-WHMCS/9.9.9';

    private FakeTransport $transport;

    protected function setUp(): void
    {
        ModuleLog::reset();
        $this->transport = new FakeTransport();
    }

    protected function tearDown(): void
    {
        $this->assertSame([], $this->transport->mismatches, 'Unexpected API requests');
        $this->assertSame(0, $this->transport->pending(), 'Expected API requests were not sent');
    }

    private function client(string $host = self::HOST, string $key = self::API_KEY, bool $forPage = false): ApiClient
    {
        return ApiClient::forServer($host, $key, $this->transport, 'TestAction', self::USER_AGENT, $forPage);
    }

    private function catch(callable $call): ApiException
    {
        try {
            $call();
        } catch (ApiException $e) {
            return $e;
        }
        $this->fail('Expected an ApiException');
    }

    public function testSendsTheKeyJsonAndAUserAgentOverHttps(): void
    {
        $this->transport->expect('POST', '/external/domain', 201, $this->addedDomainBody('example.org'));

        $result = $this->client()->addDomain(self::CUSTOMER, 'example.org', 'starter');

        $this->assertSame('example.org', $result['domain']['domainId']);
        $request = $this->transport->requests[0];
        $this->assertSame('https://dev-agency-api.cookiepal.io/external/domain', $request['url']);
        $this->assertSame(self::API_KEY, $request['headers']['x-api-key']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame('application/json', $request['headers']['Accept']);
        $this->assertSame(self::USER_AGENT, $request['headers']['User-Agent']);
        $this->assertSame(
            ['customerId' => self::CUSTOMER, 'domain' => ['domainId' => 'example.org', 'plan' => 'starter']],
            $request['json']
        );
    }

    public function testWritesWait30SecondsAndReads20(): void
    {
        $this->transport
            ->expect('POST', '/external/scan', 204)
            ->expect('GET', '/external/customers', 200, ['accounts' => []]);

        $this->client()->startScan(self::CUSTOMER, 'example.com');
        $this->client()->listCustomers();

        $this->assertSame([30, 20], array_column($this->transport->requests, 'timeout'));
    }

    public function testAPageRenderWaits5SecondsAndNeverRetries(): void
    {
        $this->transport->expect('GET', $this->customerPath(), 503, ['message' => 'Service Unavailable']);

        $e = $this->catch(fn () => $this->client(forPage: true)->getCustomer(self::CUSTOMER));

        $this->assertSame(503, $e->getStatus());
        $this->assertSame([5], array_column($this->transport->requests, 'timeout'));
    }

    public function testAGetSendsNoBodyAndNoContentType(): void
    {
        $this->transport->expect('GET', '/external/customers', 200, ['accounts' => []]);

        $this->assertSame(['accounts' => []], $this->client()->listCustomers());
        $this->assertNull($this->transport->requests[0]['body']);
        $this->assertArrayNotHasKey('Content-Type', $this->transport->requests[0]['headers']);
    }

    public function testABodylessWriteSendsNoBody(): void
    {
        $this->transport->expect('PUT', $this->domainPath('/suspension'), 200, [
            'customerId' => self::CUSTOMER, 'domainId' => 'example.com', 'suspended' => true, 'changed' => true,
        ]);

        $this->client()->suspend(self::CUSTOMER, 'example.com');

        $this->assertNull($this->transport->requests[0]['body']);
    }

    public function testATextThatIsNotUtf8IsSentWithReplacementCharacters(): void
    {
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        $this->client()->createCustomer("Caf\xE9 Ltd", 'ada@example.com', 'example.com', 'free');

        $this->assertSame("Caf\u{FFFD} Ltd", $this->transport->requests[0]['json']['customerName']);
    }

    public function testPathSegmentsAreEncoded(): void
    {
        $this->transport->expect('DELETE', '/external/domain/a%2Fb/x%20y.com', 204);

        $this->client()->deleteDomain('a/b', 'x y.com');

        $this->assertSame(['DELETE /external/domain/a%2Fb/x%20y.com'], $this->transport->sent());
    }

    public function testA204AnswersAnEmptyArray(): void
    {
        $this->transport->expect('DELETE', '/external/customer/' . self::CUSTOMER, 204, '');

        $this->assertSame([], $this->client()->deleteCustomer(self::CUSTOMER));
    }

    /** @return array<string, array{string, string}> */
    public static function hosts(): array
    {
        return [
            'bare' => ['agency-api.cookiepal.io', 'agency-api.cookiepal.io'],
            'with scheme and slash' => ['https://Dev-Agency-API.cookiepal.io/', 'dev-agency-api.cookiepal.io'],
            'with path' => ['https://agency-api.cookiepal.io/external', 'agency-api.cookiepal.io'],
            'spaces and trailing dot' => ['  agency-api.cookiepal.io.  ', 'agency-api.cookiepal.io'],
            'empty means production' => ['', 'agency-api.cookiepal.io'],
        ];
    }

    #[DataProvider('hosts')]
    public function testNormalisesTheServerHostname(string $raw, string $expected): void
    {
        $this->assertSame($expected, ApiClient::normaliseHost($raw));
    }

    /** @return array<string, array{string}> */
    public static function foreignHosts(): array
    {
        return [
            'a typo in the domain' => ['agency-api.cookiepal.com'],
            'userinfo pointing elsewhere' => ['agency-api.cookiepal.io@evil.example'],
            'a space' => ['agency api.cookiepal.io'],
            'a header injection' => ["agency-api.cookiepal.io\r\nX-Evil: 1"],
            'a lookalike suffix' => ['agency-api.cookiepal.io.evil.example'],
            'the bare domain' => ['cookiepal.io'],
        ];
    }

    #[DataProvider('foreignHosts')]
    public function testOnlyCookiepalHostsGetTheKey(string $raw): void
    {
        $e = $this->catch(fn () => $this->client($raw));

        $this->assertSame(ApiException::INVALID_HOST, $e->getErrorCode());
        $this->assertSame(
            "The server's hostname isn't a CookiePal API host. Use agency-api.cookiepal.io.",
            $e->adminMessage()
        );
        $this->assertSame([], $this->transport->requests);
    }

    public function testAnErrorCarriesStatusMessageAndCode(): void
    {
        $this->transport->expect('PUT', $this->domainPath('/plan'), 409, [
            'message' => 'Plan is managed by the customer', 'code' => 'PLAN_NOT_MANAGED_BY_AGENCY',
        ]);

        $e = $this->catch(fn () => $this->client()->setPlan(self::CUSTOMER, 'example.com', 'growth'));

        $this->assertSame(409, $e->getStatus());
        $this->assertSame('Plan is managed by the customer', $e->getMessage());
        $this->assertSame('PLAN_NOT_MANAGED_BY_AGENCY', $e->getErrorCode());
        $this->assertTrue($e->is(409, 'PLAN_NOT_MANAGED_BY_AGENCY'));
        $this->assertFalse($e->is(409, 'OTHER'));
        $this->assertSame(
            'CookiePal API error (409): Plan is managed by the customer [PLAN_NOT_MANAGED_BY_AGENCY]',
            $e->adminMessage()
        );
    }

    public function testApiGatewayDenyIsARefusedKey(): void
    {
        $this->transport->expect('GET', '/external/customers', 403, [
            'Message' => 'User is not authorized to access this resource with an explicit deny',
        ]);

        $e = $this->catch(fn () => $this->client()->listCustomers());

        $this->assertSame(403, $e->getStatus());
        $this->assertSame('User is not authorized to access this resource with an explicit deny', $e->getMessage());
        $this->assertStringStartsWith('CookiePal refused the API key (403).', $e->adminMessage());
    }

    public function testARouteTheHostDoesNotHaveYetIsNotARefusedKey(): void
    {
        $this->transport->expect(
            'POST',
            $this->customerPath() . '/sso',
            403,
            ['message' => 'Missing Authentication Token']
        );

        $e = $this->catch(fn () => $this->client()->createSsoLink(self::CUSTOMER, 'example.com'));

        $this->assertStringStartsWith(
            "This CookiePal API host doesn't have this feature yet (403).",
            $e->adminMessage()
        );
    }

    public function testApiGatewayMissingKeyIs401Unauthorized(): void
    {
        $this->transport->expect('GET', '/external/customers', 401, ['message' => 'Unauthorized']);

        $e = $this->catch(fn () => $this->client()->listCustomers());

        $this->assertSame(
            "CookiePal received no API key (401). Enter the key in the server's Password field.",
            $e->adminMessage()
        );
    }

    public function testOwnership401KeepsTheApiMessage(): void
    {
        $this->transport->expect(
            'GET',
            $this->customerPath(),
            401,
            ['message' => 'Customer does not belong to this agency']
        );

        $e = $this->catch(fn () => $this->client()->getCustomer(self::CUSTOMER));

        $this->assertSame('CookiePal API error (401): Customer does not belong to this agency', $e->adminMessage());
    }

    public function testAnErrorWithoutABodyNamesTheStatus(): void
    {
        $this->transport->expect('POST', '/external/scan', 500, 'Internal Server Error');

        $e = $this->catch(fn () => $this->client()->startScan(self::CUSTOMER, 'example.com'));

        $this->assertSame('HTTP 500', $e->getMessage());
    }

    public function testASuccessThatIsNotJsonIsABadResponse(): void
    {
        $this->transport->expect('GET', '/external/customers', 200, '<html>');

        $e = $this->catch(fn () => $this->client()->listCustomers());

        $this->assertSame(ApiException::BAD_RESPONSE, $e->getErrorCode());
        $this->assertSame("CookiePal answered with something that isn't JSON.", $e->adminMessage());
        // A bad body is an answer, not a failure to reach the API: no retry.
        $this->assertCount(1, $this->transport->requests);
    }

    public function testAReadIsRetriedOnceOnAGatewayError(): void
    {
        $this->transport
            ->expect('GET', $this->customerPath(), 503, ['message' => 'Service Unavailable'])
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([]));

        $this->assertSame(self::CUSTOMER, $this->client()->getCustomer(self::CUSTOMER)['customerId']);
        $this->assertCount(2, $this->transport->requests);
    }

    public function testAReadIsRetriedOnceWhenNoAnswerArrives(): void
    {
        $this->transport
            ->expectNetworkError('GET', '/external/customers')
            ->expectNetworkError('GET', '/external/customers');

        $e = $this->catch(fn () => $this->client()->listCustomers());

        $this->assertSame(0, $e->getStatus());
        $this->assertSame(ApiException::NETWORK_ERROR, $e->getErrorCode());
        $this->assertSame("Couldn't reach CookiePal (curl error 28): Operation timed out", $e->adminMessage());
        $this->assertCount(2, $this->transport->requests);
    }

    public function testAWriteIsNeverRetried(): void
    {
        $this->transport->expect('POST', '/external/customer', 503, ['message' => 'Service Unavailable']);
        $e = $this->catch(fn () => $this->client()->createCustomer('Ada', 'ada@example.com', 'example.com', 'free'));
        $this->assertSame(503, $e->getStatus());

        $this->transport->expectNetworkError('DELETE', '/external/customer/' . self::CUSTOMER);
        $e = $this->catch(fn () => $this->client()->deleteCustomer(self::CUSTOMER));
        $this->assertSame(0, $e->getStatus());

        $this->assertCount(2, $this->transport->requests);
    }

    public function testNoKeyFailsBeforeAnyRequest(): void
    {
        $e = $this->catch(fn () => $this->client(self::HOST, '  ')->listCustomers());

        $this->assertSame(ApiException::NO_API_KEY, $e->getErrorCode());
        $this->assertSame(
            "No CookiePal API key: enter your agency API key in the server's Password field.",
            $e->adminMessage()
        );
        $this->assertSame([], $this->transport->requests);
    }

    public function testEveryAttemptIsLoggedWithTheKeyMaskedAndNeverInTheRequest(): void
    {
        $this->transport
            ->expect('GET', '/external/customers', 502, ['message' => 'Bad Gateway'])
            ->expect('GET', '/external/customers', 200, ['accounts' => []]);

        $this->client()->listCustomers();

        $this->assertCount(2, ModuleLog::$calls);
        foreach (ModuleLog::$calls as $call) {
            $this->assertSame('cookiepal', $call['module']);
            $this->assertSame('TestAction', $call['action']);
            $this->assertSame([self::API_KEY], $call['replaceVars']);
            $this->assertStringNotContainsString(self::API_KEY, (string) $call['request']);
            $this->assertStringStartsWith(
                'GET https://dev-agency-api.cookiepal.io/external/customers',
                (string) $call['request']
            );
        }
        $this->assertStringStartsWith('HTTP 502', (string) ModuleLog::$calls[0]['response']);
        $this->assertSame(['accounts' => []], ModuleLog::$calls[1]['processed']);
    }

    public function testTheSignInLinkIsMaskedInTheLog(): void
    {
        $url = 'https://app.cookiepal.io/sso#code=Zm9vYmFyYmF6';
        $this->transport->expect('POST', $this->customerPath() . '/sso', 200, [
            'url' => $url, 'expiresAt' => '2026-11-26T10:01:00.000Z',
        ]);

        $this->assertSame($url, $this->client()->createSsoLink(self::CUSTOMER, 'example.com')['url']);

        $call = ModuleLog::$calls[0];
        $this->assertSame(['domainId' => 'example.com'], $this->transport->requests[0]['json']);
        $this->assertContains($url, $call['replaceVars']);
        $this->assertContains('Zm9vYmFyYmF6', $call['replaceVars']);
        $this->assertStringNotContainsString('Zm9vYmFyYmF6', ModuleLog::asShown($call));
        $this->assertStringContainsString('2026-11-26T10:01:00.000Z', ModuleLog::asShown($call));
    }

    public function testTheSignInLinkIsMaskedWhenCookiePalEscapesItsSlashes(): void
    {
        $this->transport->expect(
            'POST',
            $this->customerPath() . '/sso',
            200,
            '{"url":"https:\\/\\/app.cookiepal.io\\/sso\\/start","expiresAt":"2026-11-26T10:01:00.000Z"}'
        );

        $this->assertSame(
            'https://app.cookiepal.io/sso/start',
            $this->client()->createSsoLink(self::CUSTOMER, 'example.com')['url']
        );

        $shown = ModuleLog::asShown(ModuleLog::$calls[0]);
        $this->assertStringNotContainsString('app.cookiepal.io', $shown);
        $this->assertStringContainsString('2026-11-26T10:01:00.000Z', $shown);
    }

    public function testANetworkErrorIsLogged(): void
    {
        $this->transport->expectNetworkError('POST', '/external/scan');

        $this->catch(fn () => $this->client()->startScan(self::CUSTOMER, 'example.com'));

        $this->assertCount(1, ModuleLog::$calls);
        $this->assertSame(
            "Couldn't reach CookiePal (curl error 28): Operation timed out",
            ModuleLog::$calls[0]['response']
        );
    }
}
