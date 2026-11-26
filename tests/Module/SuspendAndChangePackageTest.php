<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use CookiePal\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Suspend, unsuspend and change package. */
final class SuspendAndChangePackageTest extends ModuleTestCase
{
    public function testSuspendPutsTheSuspension(): void
    {
        $this->seedService();
        $this->transport->expect('PUT', $this->domainPath('/suspension'), 200, [
            'customerId' => self::CUSTOMER, 'domainId' => 'example.com', 'suspended' => true, 'changed' => true,
        ]);

        $this->assertSame('success', cookiepal_SuspendAccount($this->params()));
        $this->assertNull($this->transport->requests[0]['body']);
    }

    public function testSuspendingASuspendedWebsiteIsSuccess(): void
    {
        $this->seedService();
        $this->transport->expect('PUT', $this->domainPath('/suspension'), 200, [
            'customerId' => self::CUSTOMER, 'domainId' => 'example.com', 'suspended' => true, 'changed' => false,
        ]);

        $this->assertSame('success', cookiepal_SuspendAccount($this->params()));
    }

    public function testSuspendingAWebsiteScheduledForDeletionIsAClearMessage(): void
    {
        $this->seedService();
        $this->transport->expect('PUT', $this->domainPath('/suspension'), 409, [
            'message' => 'Domain is scheduled for deletion', 'code' => 'SCHEDULED_FOR_DELETION',
        ]);

        $this->assertSame(
            "This CookiePal website is scheduled for deletion, so it can't be suspended.",
            cookiepal_SuspendAccount($this->params())
        );
    }

    public function testSuspendWithoutAWebsiteIsAClearMessage(): void
    {
        $this->assertSame(
            'This service has no CookiePal website yet. Run Create first.',
            cookiepal_SuspendAccount($this->params())
        );
    }

    public function testSuspendOnATerminatedServiceIsAClearMessage(): void
    {
        $this->seedService(terminatedAt: '2026-11-21 09:00:00');

        $this->assertSame(
            "This service's CookiePal website was terminated on 2026-11-21 09:00:00 UTC.",
            cookiepal_SuspendAccount($this->params())
        );
    }

    public function testSuspendReportsAMissingDomain(): void
    {
        $this->seedService();
        $this->transport->expect('PUT', $this->domainPath('/suspension'), 404, [
            'message' => 'domain not found', 'code' => 'DOMAIN_NOT_FOUND',
        ]);

        $this->assertSame(
            'CookiePal API error (404): domain not found [DOMAIN_NOT_FOUND]',
            cookiepal_SuspendAccount($this->params())
        );
    }

    public function testUnsuspendDeletesTheSuspension(): void
    {
        $this->seedService();
        $this->transport->expect('DELETE', $this->domainPath('/suspension'), 200, [
            'customerId' => self::CUSTOMER, 'domainId' => 'example.com', 'suspended' => false, 'changed' => true,
        ]);

        $this->assertSame('success', cookiepal_UnsuspendAccount($this->params()));
    }

    public function testUnsuspendingAWebsiteScheduledForDeletionIsAClearMessage(): void
    {
        $this->seedService();
        $this->transport->expect('DELETE', $this->domainPath('/suspension'), 409, [
            'message' => 'Domain is scheduled for deletion', 'code' => 'SCHEDULED_FOR_DELETION',
        ]);

        $this->assertSame(
            "This CookiePal website is scheduled for deletion, so it can't be unsuspended.",
            cookiepal_UnsuspendAccount($this->params())
        );
    }

    public function testUnsuspendReportsAnyOtherConflictAsItIs(): void
    {
        $this->seedService();
        $this->transport->expect('DELETE', $this->domainPath('/suspension'), 409, ['message' => 'Conflict']);

        $this->assertSame('CookiePal API error (409): Conflict', cookiepal_UnsuspendAccount($this->params()));
    }

    public function testUnsuspendReportsOwnership(): void
    {
        $this->seedService();
        $this->transport->expect('DELETE', $this->domainPath('/suspension'), 401, [
            'message' => 'Customer does not belong to this agency',
        ]);

        $this->assertSame(
            'CookiePal API error (401): Customer does not belong to this agency',
            cookiepal_UnsuspendAccount($this->params())
        );
    }

    public function testChangePackagePutsTheNewPlan(): void
    {
        $this->seedService();
        $this->transport->expect('PUT', $this->domainPath('/plan'), 200, [
            'customerId' => self::CUSTOMER, 'domainId' => 'example.com', 'plan' => 'business', 'changed' => true,
        ]);

        $this->assertSame('success', cookiepal_ChangePackage($this->params(['configoption1' => 'business'])));
        $this->assertSame(['plan' => 'business'], $this->transport->requests[0]['json']);
    }

    public function testChangePackageUsesThePlanConfigurableOption(): void
    {
        $this->seedService();
        $this->transport->expect('PUT', $this->domainPath('/plan'), 200, [
            'customerId' => self::CUSTOMER, 'domainId' => 'example.com', 'plan' => 'free', 'changed' => true,
        ]);

        cookiepal_ChangePackage($this->params(['configoptions' => ['plan' => 'free']]));

        $this->assertSame(['plan' => 'free'], $this->transport->requests[0]['json']);
    }

    public function testAnInvalidPlanConfigurableOptionIsRefused(): void
    {
        $this->seedService();

        $this->assertSame(
            'The "Plan" configurable option is "Gold". Its values must be free, starter, growth or business.',
            cookiepal_ChangePackage($this->params(['configoptions' => ['Plan' => 'Gold']]))
        );
    }

    /** @return list<array{string, string}> */
    public static function planConflicts(): array
    {
        return [
            ['SCHEDULED_FOR_DELETION', "This CookiePal website is scheduled for deletion, so its plan can't change."],
            [
                'PLAN_NOT_MANAGED_BY_AGENCY',
                "The customer pays for this website's plan in CookiePal themselves, so WHMCS can't change it.",
            ],
            ['FREE_PLAN_OVER_LIMIT', 'This website has more traffic than the free plan allows. Choose a paid plan.'],
            ['PLAN_CHANGE_CONFLICT', 'Another plan change for this website was in progress. Try again in a minute.'],
        ];
    }

    #[DataProvider('planConflicts')]
    public function testEachPlanConflictIsAClearMessage(string $code, string $message): void
    {
        $this->seedService();
        $this->transport->expect('PUT', $this->domainPath('/plan'), 409, ['message' => 'conflict', 'code' => $code]);

        $this->assertSame($message, cookiepal_ChangePackage($this->params(['configoption1' => 'free'])));
    }

    public function testChangePackageReportsABadPlan(): void
    {
        $this->seedService();
        $this->transport->expect(
            'PUT',
            $this->domainPath('/plan'),
            400,
            ['message' => 'invalid parameter value: plan']
        );

        $this->assertSame(
            'CookiePal API error (400): invalid parameter value: plan',
            cookiepal_ChangePackage($this->params())
        );
    }
}
