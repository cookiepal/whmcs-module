<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use CookiePal\Tests\Support\FakeDatabase;
use CookiePal\Tests\Support\ModuleLog;
use CookiePal\Tests\Support\ModuleTestCase;
use WHMCS\Module\Server\CookiePal\Module;
use WHMCS\Module\Server\CookiePal\ServiceStore;
use WHMCS\Module\Server\CookiePal\Transport;

/** The service's tab in the admin area, and its "Start scan" and "Refresh status" buttons. */
final class AdminAreaTest extends ModuleTestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, string>
     */
    private function fieldsFor(array $overrides): array
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 200, $this->customerBody([
            $this->domainItem('example.com', $overrides),
        ]));

        return cookiepal_AdminServicesTabFields($this->params());
    }

    public function testShowsTheIdsAndTheWebsitesStatus(): void
    {
        $fields = $this->fieldsFor(['verification' => 'verified', 'suspended' => true, 'plan' => 'starter']);

        $this->assertSame([
            'CookiePal customer ID' => self::CUSTOMER,
            'CookiePal website' => 'example.com',
            'Plan' => 'Starter',
            'Banner installed' => 'Yes (verified)',
            'Script URL' => '<a href="' . self::HANDLER . '" target="_blank" rel="noopener">' . self::HANDLER . '</a>',
            'Suspended' => 'Yes',
            'CookiePal status' => 'Active',
        ], $fields);
        $this->assertSame([5], array_column($this->transport->requests, 'timeout'));
    }

    public function testShowsAScheduledDeletionAndEscapes(): void
    {
        $fields = $this->fieldsFor([
            'status' => 'inactive',
            'scheduledDeletion' => '2026-11-27T10:00:00Z',
            'verification' => '<b>odd</b>',
        ]);

        $this->assertSame('Inactive, scheduled for deletion on 2026-11-27T10:00:00Z', $fields['CookiePal status']);
        $this->assertSame('No (&lt;b&gt;odd&lt;/b&gt;)', $fields['Banner installed']);
    }

    public function testAPlanOutsideTheFourIsShownAsOther(): void
    {
        $this->assertSame('Other (set in CookiePal)', $this->fieldsFor(['plan' => 'tier1'])['Plan']);
    }

    public function testAScriptUrlThatIsNotSafeIsTextNotALink(): void
    {
        $fields = $this->fieldsFor(['handler' => 'javascript:alert("x")']);

        $this->assertSame('javascript:alert(&quot;x&quot;)', $fields['Script URL']);
    }

    public function testBeforeCreate(): void
    {
        $this->assertSame(
            ['CookiePal' => 'No CookiePal website yet. Run Create to set one up.'],
            cookiepal_AdminServicesTabFields($this->params())
        );
    }

    public function testAfterTerminate(): void
    {
        $this->seedService(terminatedAt: '2026-11-21 09:00:00');

        $fields = cookiepal_AdminServicesTabFields($this->params());

        $this->assertSame('Terminated on 2026-11-21 09:00:00 UTC', $fields['CookiePal status']);
        $this->assertSame([], $this->transport->requests);
    }

    public function testAWebsiteNoLongerOnTheCustomer(): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 200, $this->customerBody([]));

        $this->assertSame(
            'This website is no longer on the CookiePal customer.',
            cookiepal_AdminServicesTabFields($this->params())['CookiePal status']
        );
    }

    public function testAnApiErrorIsShown(): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 403, ['message' => 'Forbidden']);

        $fields = cookiepal_AdminServicesTabFields($this->params());

        $this->assertSame(self::CUSTOMER, $fields['CookiePal customer ID']);
        $this->assertStringStartsWith(
            "Couldn't load: CookiePal refused the API key (403).",
            html_entity_decode($fields['CookiePal status'], ENT_QUOTES)
        );
    }

    public function testAnUnexpectedFailureIsShownAndLogged(): void
    {
        $this->seedService();
        Module::useTransport(new class implements Transport {
            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $fields = cookiepal_AdminServicesTabFields($this->params());

        $this->assertSame(
            "Couldn't load: CookiePal module error: boom",
            html_entity_decode($fields['CookiePal status'], ENT_QUOTES)
        );
        $last = end(ModuleLog::$calls);
        $this->assertNotFalse($last);
        $this->assertSame('AdminServicesTabFields', $last['action']);
    }

    public function testATableThatCannotBeReadIsShownAndLogged(): void
    {
        (new ServiceStore())->find(0);
        unset(FakeDatabase::$tables[ServiceStore::TABLE]);

        $fields = cookiepal_AdminServicesTabFields($this->params());

        $this->assertSame(
            "Couldn't read the module's table: Base table or view not found: mod_cookiepal_services",
            html_entity_decode($fields['CookiePal'], ENT_QUOTES)
        );
        $this->assertCount(1, ModuleLog::$calls);
    }

    public function testTheButtons(): void
    {
        $this->assertSame(
            ['Start scan' => 'StartScan', 'Refresh status' => 'RefreshStatus'],
            cookiepal_AdminCustomButtonArray()
        );
        $this->assertTrue(function_exists('cookiepal_StartScan'));
        $this->assertTrue(function_exists('cookiepal_RefreshStatus'));
    }

    public function testStartScanPostsTheScan(): void
    {
        $this->seedService();
        $this->transport->expect('POST', '/external/scan', 204);

        $this->assertSame('success', cookiepal_StartScan($this->params()));
        $this->assertSame(
            ['customerId' => self::CUSTOMER, 'domainId' => 'example.com'],
            $this->transport->requests[0]['json']
        );
    }

    public function testStartScanPassesTheApisReasonOn(): void
    {
        $this->seedService();
        $this->transport->expect(
            'POST',
            '/external/scan',
            400,
            ['message' => 'Domain must be verified and active to start a scan']
        );

        $this->assertSame(
            'CookiePal API error (400): Domain must be verified and active to start a scan',
            cookiepal_StartScan($this->params())
        );
    }

    public function testRefreshStatusReadsTheCustomer(): void
    {
        $this->seedService();
        $this->transport->expect(
            'GET',
            $this->customerPath(),
            200,
            $this->customerBody([$this->domainItem('example.com')])
        );

        $this->assertSame('success', cookiepal_RefreshStatus($this->params()));
    }

    public function testRefreshStatusReportsAMissingWebsite(): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 200, $this->customerBody([]));

        $this->assertSame(
            'The website example.com is no longer on this CookiePal customer.',
            cookiepal_RefreshStatus($this->params())
        );
    }
}
