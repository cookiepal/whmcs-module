<?php

declare(strict_types=1);

namespace CookiePal\Tests\Api;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\CookiePal\ApiException;
use WHMCS\Module\Server\CookiePal\CurlTransport;

/** The real transport, without the network: a refused connection, and a URL that isn't HTTPS. */
final class CurlTransportTest extends TestCase
{
    private function catch(string $url): ApiException
    {
        try {
            (new CurlTransport())->send('POST', $url, ['x-api-key' => 'k'], '{}', 2);
        } catch (ApiException $e) {
            return $e;
        }
        $this->fail('Expected an ApiException');
    }

    public function testARefusedConnectionIsANetworkError(): void
    {
        $e = $this->catch('https://127.0.0.1:9/external/customers');

        $this->assertSame(0, $e->getStatus());
        $this->assertSame(ApiException::NETWORK_ERROR, $e->getErrorCode());
        $this->assertStringStartsWith(
            'Couldn\'t reach CookiePal (curl error ' . CURLE_COULDNT_CONNECT . '): ',
            $e->getMessage()
        );
    }

    public function testPlainHttpIsRefusedByCurlItself(): void
    {
        // Port 9 refuses connections too, so the check is that curl refused the protocol.
        $e = $this->catch('http://127.0.0.1:9/external/customer');

        $this->assertStringStartsWith(
            'Couldn\'t reach CookiePal (curl error ' . CURLE_UNSUPPORTED_PROTOCOL . '): ',
            $e->getMessage()
        );
    }
}
