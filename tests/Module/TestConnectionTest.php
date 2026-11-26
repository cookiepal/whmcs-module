<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use CookiePal\Tests\Support\ModuleLog;
use CookiePal\Tests\Support\ModuleTestCase;
use WHMCS\Module\Server\CookiePal\Module;
use WHMCS\Module\Server\CookiePal\Transport;

/** The server's Test Connection button. */
final class TestConnectionTest extends ModuleTestCase
{
    public function testListsCustomers(): void
    {
        $this->transport->expect('GET', '/external/customers', 200, ['accounts' => []]);

        $this->assertSame(['success' => true, 'error' => ''], cookiepal_TestConnection($this->params()));
        $this->assertSame(
            'https://dev-agency-api.cookiepal.io/external/customers',
            $this->transport->requests[0]['url']
        );
    }

    public function testAnEmptyHostnameMeansProduction(): void
    {
        $this->transport->expect('GET', '/external/customers', 200, ['accounts' => []]);

        cookiepal_TestConnection($this->params(['serverhostname' => '']));

        $this->assertSame('https://agency-api.cookiepal.io/external/customers', $this->transport->requests[0]['url']);
    }

    public function testAHostnameOutsideCookiepalIsRefusedWithoutACall(): void
    {
        $this->assertSame(
            [
                'success' => false,
                'error' => "The server's hostname isn't a CookiePal API host. Use agency-api.cookiepal.io.",
            ],
            cookiepal_TestConnection($this->params(['serverhostname' => 'agency-api.cookiepal.com']))
        );
        $this->assertSame([], $this->transport->requests);
    }

    public function testReportsARefusedKey(): void
    {
        $this->transport->expect('GET', '/external/customers', 403, ['message' => 'Forbidden']);

        $result = cookiepal_TestConnection($this->params());

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('CookiePal refused the API key (403).', $result['error']);
    }

    public function testWithoutAKey(): void
    {
        $this->assertSame(
            [
                'success' => false,
                'error' => "No CookiePal API key: enter your agency API key in the server's Password field.",
            ],
            cookiepal_TestConnection($this->params(['serverpassword' => '']))
        );
    }

    public function testAnUnexpectedFailureIsReportedAndLogged(): void
    {
        Module::useTransport(new class implements Transport {
            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->assertSame(
            ['success' => false, 'error' => 'CookiePal module error: boom'],
            cookiepal_TestConnection($this->params())
        );
        $this->assertSame('TestConnection', ModuleLog::$calls[0]['action'] ?? null);
    }
}
