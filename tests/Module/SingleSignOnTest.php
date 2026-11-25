<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use CookiePal\Tests\Support\ModuleLog;
use CookiePal\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WHMCS\Module\Server\CookiePal\Module;
use WHMCS\Module\Server\CookiePal\Transport;

/**
 * "Open CookiePal dashboard". In the client area, WHMCS shows errorMsg to the client, so only the
 * language file's text goes there; the admin area (ADMINAREA defined) gets the detail.
 */
final class SingleSignOnTest extends ModuleTestCase
{
    private const LINK = 'https://app.cookiepal.io/sso#code=Zm9vYmFy';
    private const UNAVAILABLE = "We couldn't open your CookiePal dashboard right now. "
        . 'Try again in a few minutes, or contact us.';

    /** @param array<string, string> $body */
    private function ssoAnswers(int $status, array $body): void
    {
        $this->seedService();
        $this->transport->expect('POST', $this->customerPath() . '/sso', $status, $body);
    }

    public function testRedirectsToTheLinkForTheServicesWebsite(): void
    {
        $this->ssoAnswers(200, ['url' => self::LINK, 'expiresAt' => '2026-11-26T10:01:00.000Z']);

        $this->assertSame(
            ['success' => true, 'redirectTo' => self::LINK],
            cookiepal_ServiceSingleSignOn($this->params())
        );
        $this->assertSame(['domainId' => 'example.com'], $this->transport->requests[0]['json']);
    }

    public function testAnIneligibleUserIsToldWhereToSignIn(): void
    {
        $this->ssoAnswers(409, ['message' => 'User cannot sign in with a link', 'code' => 'SSO_USER_NOT_ELIGIBLE']);

        $this->assertSame(
            ['success' => false, 'errorMsg' => 'Sign in at app.cookiepal.io with the email the invite went to.'],
            cookiepal_ServiceSingleSignOn($this->params())
        );
    }

    /** @return array<string, array{int, array<string, string>}> */
    public static function failures(): array
    {
        return [
            'no user to sign in as' => [404, ['message' => 'user not found', 'code' => 'USER_NOT_FOUND']],
            'customer gone' => [404, ['message' => 'customer not found', 'code' => 'CUSTOMER_NOT_FOUND']],
            'a refused key' => [403, ['message' => 'Forbidden']],
            'a bad request' => [400, ['message' => 'Domain does not belong to the specified customer']],
            'a link that is not https' => [200, ['url' => 'javascript:alert(1)']],
        ];
    }

    /** @param array<string, string> $body */
    #[DataProvider('failures')]
    public function testTheClientSeesOnlyAGenericSentence(int $status, array $body): void
    {
        $this->ssoAnswers($status, $body);

        $this->assertSame(
            ['success' => false, 'errorMsg' => self::UNAVAILABLE],
            cookiepal_ServiceSingleSignOn($this->params())
        );
    }

    public function testWithoutAWebsiteTheClientSeesTheGenericSentence(): void
    {
        $this->assertSame(
            ['success' => false, 'errorMsg' => self::UNAVAILABLE],
            cookiepal_ServiceSingleSignOn($this->params())
        );
        $this->assertSame([], $this->transport->requests);
    }

    public function testAnUnexpectedFailureIsLogged(): void
    {
        $this->seedService();
        Module::useTransport(new class implements Transport {
            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->assertSame(
            ['success' => false, 'errorMsg' => self::UNAVAILABLE],
            cookiepal_ServiceSingleSignOn($this->params())
        );
        $last = end(ModuleLog::$calls);
        $this->assertNotFalse($last);
        $this->assertSame(['ServiceSingleSignOn', 'RuntimeException: boom'], [$last['action'], $last['response']]);
    }

    #[RunInSeparateProcess]
    public function testTheAdminAreaGetsTheDetail(): void
    {
        define('ADMINAREA', true);

        $this->ssoAnswers(400, ['message' => 'Domain does not belong to the specified customer']);
        $this->assertSame(
            [
                'success' => false,
                'errorMsg' => 'CookiePal API error (400): Domain does not belong to the specified customer',
            ],
            cookiepal_ServiceSingleSignOn($this->params())
        );

        $this->transport->expect(
            'POST',
            $this->customerPath() . '/sso',
            404,
            ['message' => 'user not found', 'code' => 'USER_NOT_FOUND']
        );
        $this->assertSame(
            'This CookiePal customer has no user to sign in as. Contact CookiePal support (support@cookiepal.io).',
            cookiepal_ServiceSingleSignOn($this->params())['errorMsg']
        );

        $this->transport->expect(
            'POST',
            $this->customerPath() . '/sso',
            409,
            ['message' => 'no', 'code' => 'SSO_USER_NOT_ELIGIBLE']
        );
        $this->assertStringStartsWith(
            "The customer's CookiePal user can't sign in with a link",
            cookiepal_ServiceSingleSignOn($this->params())['errorMsg']
        );
    }
}
