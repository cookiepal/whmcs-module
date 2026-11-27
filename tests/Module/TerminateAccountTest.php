<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use CookiePal\Tests\Support\ModuleLog;
use CookiePal\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WHMCS\Module\Server\CookiePal\ServiceStore;

final class TerminateAccountTest extends ModuleTestCase
{
    private const ONLY_ACTIVE_DOMAIN = [
        'message' => "Cannot delete the customer's only active domain",
        'code' => 'ONLY_ACTIVE_DOMAIN',
    ];

    private const OTHER_WEBSITES = [
        'message' => 'This customer has websites your agency does not manage, so the account cannot be deleted. '
            . 'Delete your own websites instead.',
        'code' => 'CUSTOMER_HAS_OTHER_WEBSITES',
    ];

    private const NOT_LINKED = ['message' => 'This website is not linked to your agency.'];

    private function deleteDomainPath(string $domainId = 'example.com'): string
    {
        return '/external/domain/' . self::CUSTOMER . '/' . $domainId;
    }

    private function deleteCustomerPath(): string
    {
        return '/external/customer/' . self::CUSTOMER;
    }

    public function testDeletesOnlyTheWebsiteWhenTheCustomerHasAnotherActiveOne(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com'),
                $this->domainItem('second.com', ['suspended' => true]),
            ]))
            ->expect('DELETE', $this->deleteDomainPath(), 204);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
    }

    public function testDeletesTheCustomerWithItsLastWebsite(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]))
            ->expect('DELETE', $this->deleteCustomerPath(), 204);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
    }

    public function testWebsitesBeingRemovedDoNotKeepTheCustomer(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com'),
                $this->domainItem('old.com', ['status' => 'inactive', 'scheduledDeletion' => '2026-11-27T10:00:00Z']),
                $this->domainItem('older.com', ['scheduledDeletion' => '2026-11-27T10:00:00Z']),
            ]))
            ->expect('DELETE', $this->deleteCustomerPath(), 204);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
    }

    public function testNeverDeletesTheCustomerWhileAnotherLiveServiceUsesIt(): void
    {
        // Service 102's website is inactive in CookiePal, so the API sees example.com as the only
        // active one; service 102 still points at the customer, so only example.com goes.
        $this->seedService();
        $this->seedService(102, 7, self::CUSTOMER, 'second.com');
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com'),
                $this->domainItem('second.com', ['status' => 'inactive']),
            ]))
            ->expect('DELETE', $this->deleteDomainPath(), 400, self::ONLY_ACTIVE_DOMAIN);

        $this->assertSame(
            "CookiePal won't remove example.com because it's the customer's only active website, and service #102 "
            . 'still uses that customer. Terminate service #102 first, then run Terminate again, or contact '
            . 'CookiePal support (support@cookiepal.io).',
            cookiepal_TerminateAccount($this->params())
        );
        $this->assertNull($this->terminatedAt());
        $this->assertNull($this->terminatedAt(102));
    }

    public function testTheOnlyActiveWebsiteRefusalWithoutAnotherServiceIsReportedAsIs(): void
    {
        // CookiePal's view changed between the read and the delete: second.com stopped being active.
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com'),
                $this->domainItem('second.com'),
            ]))
            ->expect('DELETE', $this->deleteDomainPath(), 400, self::ONLY_ACTIVE_DOMAIN);

        $this->assertSame(
            "CookiePal API error (400): Cannot delete the customer's only active domain [ONLY_ACTIVE_DOMAIN]",
            cookiepal_TerminateAccount($this->params())
        );
        $this->assertNull($this->terminatedAt());
    }

    public function testAnInactiveWebsiteIsDeleted(): void
    {
        $this->seedService();
        $this->seedService(102, 7, self::CUSTOMER, 'second.com');
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com', ['status' => 'inactive']),
                $this->domainItem('second.com'),
            ]))
            ->expect('DELETE', $this->deleteDomainPath(), 204);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
    }

    public function testTheLastServiceWithOnlyInactiveWebsitesLeftDeletesTheCustomer(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com'),
                $this->domainItem('old.com', ['status' => 'inactive']),
            ]))
            ->expect('DELETE', $this->deleteCustomerPath(), 204);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
    }

    public function testAnInactiveWebsiteTheApiRefusesToDeleteStillEndsTheService(): void
    {
        // The API's "only active domain" check can count against an inactive website when exactly one other
        // is active. The website is already inactive, so the service ends; the Activity Log says so.
        $this->seedService();
        $this->seedService(102, 7, self::CUSTOMER, 'second.com');
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com', ['status' => 'inactive']),
                $this->domainItem('second.com'),
            ]))
            ->expect(
                'DELETE',
                $this->deleteDomainPath(),
                400,
                ['message' => "Cannot delete the customer's only active domain"]
            );

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
        $this->assertNull($this->terminatedAt(102));
        $this->assertSame(
            [
                "CookiePal: service #101 is terminated, but CookiePal didn't delete its inactive website example.com "
                . 'on customer ' . self::CUSTOMER . ': CookiePal API error (400): Cannot delete the customer\'s only '
                . 'active domain',
            ],
            ModuleLog::$activity
        );
    }

    public function testAnInactiveWebsiteRefusedWithoutAnotherLiveServiceStillEndsTheService(): void
    {
        // No other WHMCS service, but the customer has another active website made outside WHMCS.
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com', ['status' => 'inactive']),
                $this->domainItem('shop.example.org'),
            ]))
            ->expect(
                'DELETE',
                $this->deleteDomainPath(),
                400,
                ['message' => "Cannot delete the customer's only active domain"]
            );

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
        $this->assertCount(1, ModuleLog::$activity);
    }

    public function testAnInactiveWebsiteWhoseDeleteFailsOtherwiseLeavesTheServiceLive(): void
    {
        $this->seedService();
        $this->seedService(102, 7, self::CUSTOMER, 'second.com');
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com', ['status' => 'inactive']),
                $this->domainItem('second.com'),
            ]))
            ->expect('DELETE', $this->deleteDomainPath(), 500, ['message' => 'Internal server error']);

        $this->assertSame(
            'CookiePal API error (500): Internal server error',
            cookiepal_TerminateAccount($this->params())
        );
        $this->assertNull($this->terminatedAt());
        $this->assertSame([], array_filter(
            ModuleLog::$activity,
            fn (string $line): bool => str_contains($line, "didn't delete its inactive website")
        ));
    }

    public function testAWebsiteAlreadyBeingRemovedIsNotDeletedAgainAndTheSharedCustomerStays(): void
    {
        // A retried Terminate: example.com was deleted, but marking the row failed. Service 102 is live.
        $this->seedService();
        $this->seedService(102, 7, self::CUSTOMER, 'second.com');
        $this->transport->expect('GET', $this->customerPath(), 200, $this->customerBody([
            $this->domainItem('example.com', ['status' => 'inactive', 'scheduledDeletion' => '2026-11-27T10:00:00Z']),
            $this->domainItem('second.com'),
        ]));

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
        $this->assertNull($this->terminatedAt(102));
    }

    public function testAWebsiteGoneFromTheCustomerLeavesOtherWebsitesAlone(): void
    {
        $this->seedService();
        $this->transport->expect(
            'GET',
            $this->customerPath(),
            200,
            $this->customerBody([$this->domainItem('second.com')])
        );

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
    }

    public function testTheDomainIsMatchedWithoutCase(): void
    {
        $this->seedService(domainId: 'Example.com');
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com'),
                $this->domainItem('second.com'),
            ]))
            ->expect('DELETE', $this->deleteDomainPath('Example.com'), 204);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
    }

    public function testACustomerAlreadyGoneIsTerminated(): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 404, ['message' => 'customer not found']);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
    }

    public function testAFailedReadLeavesTheServiceLive(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 500, ['message' => 'Internal server error']);

        $this->assertSame(
            'CookiePal API error (500): Internal server error',
            cookiepal_TerminateAccount($this->params())
        );
        $this->assertNull($this->terminatedAt());
    }

    public function testAWebsiteDeletedMeanwhileIsTerminated(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com'), $this->domainItem('second.com'),
            ]))
            ->expect('DELETE', $this->deleteDomainPath(), 404, ['message' => 'domain not found']);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
    }

    public function testAFailedWebsiteDeleteLeavesTheServiceLive(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com'), $this->domainItem('second.com'),
            ]))
            ->expect('DELETE', $this->deleteDomainPath(), 500, ['message' => 'Internal server error']);

        $this->assertSame(
            'CookiePal API error (500): Internal server error',
            cookiepal_TerminateAccount($this->params())
        );
        $this->assertNull($this->terminatedAt());
    }

    public function testACustomerDeletedMeanwhileIsTerminated(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]))
            ->expect('DELETE', $this->deleteCustomerPath(), 404, ['message' => 'customer not found']);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
    }

    public function testAFailedCustomerDeleteLeavesTheServiceLive(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]))
            ->expect('DELETE', $this->deleteCustomerPath(), 409, [
                'message' => 'A cancellation for customer ' . self::CUSTOMER
                    . ' is in progress. Try again in a few minutes.',
            ]);

        $result = cookiepal_TerminateAccount($this->params());

        $this->assertStringStartsWith('CookiePal API error (409): A cancellation for customer', $result);
        $this->assertNull($this->terminatedAt());
    }

    public function testACustomerWithWebsitesTheAgencyDoesNotManageLosesOnlyTheServicesWebsite(): void
    {
        // The GET lists only this agency's websites, so the customer looks like it has no other.
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]))
            ->expect('DELETE', $this->deleteCustomerPath(), 409, self::OTHER_WEBSITES)
            ->expect('DELETE', $this->deleteDomainPath(), 204);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
        $this->assertSame([], ModuleLog::$activity);
    }

    public function testTheFallbackWebsiteDeletedMeanwhileIsTerminated(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]))
            ->expect('DELETE', $this->deleteCustomerPath(), 409, self::OTHER_WEBSITES)
            ->expect('DELETE', $this->deleteDomainPath(), 404, ['message' => 'domain not found']);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
    }

    public function testAFailedFallbackWebsiteDeleteLeavesTheServiceLive(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]))
            ->expect('DELETE', $this->deleteCustomerPath(), 409, self::OTHER_WEBSITES)
            ->expect('DELETE', $this->deleteDomainPath(), 500, ['message' => 'Internal server error']);

        $this->assertSame(
            'CookiePal API error (500): Internal server error',
            cookiepal_TerminateAccount($this->params())
        );
        $this->assertNull($this->terminatedAt());
    }

    public function testAnInactiveFallbackWebsiteTheApiRefusesToDeleteStillEndsTheService(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com', ['status' => 'inactive']),
            ]))
            ->expect('DELETE', $this->deleteCustomerPath(), 409, self::OTHER_WEBSITES)
            ->expect('DELETE', $this->deleteDomainPath(), 400, ['message' => 'Bad request']);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
        $this->assertSame(
            [
                "CookiePal: service #101 is terminated, but CookiePal didn't delete its inactive website example.com "
                . 'on customer ' . self::CUSTOMER . ': CookiePal API error (400): Bad request',
            ],
            ModuleLog::$activity
        );
    }

    public function testAWebsiteNoLongerListedOnACustomerWithOtherWebsitesIsTerminatedWithoutACall(): void
    {
        // Already deleted, or moved away from this agency: the GET lists none of this agency's websites.
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([]))
            ->expect('DELETE', $this->deleteCustomerPath(), 409, self::OTHER_WEBSITES);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
        $this->assertSame(
            [
                'CookiePal: service #101 is terminated without a call to CookiePal: its website example.com is no '
                . "longer among your agency's websites on customer " . self::CUSTOMER . ', and that customer has '
                . "websites your agency doesn't manage.",
            ],
            ModuleLog::$activity
        );
    }

    public function testAFallbackWebsiteAlreadyBeingRemovedIsNotDeletedAgain(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem(
                'example.com',
                ['status' => 'inactive', 'scheduledDeletion' => '2026-11-27T10:00:00Z']
            )]))
            ->expect('DELETE', $this->deleteCustomerPath(), 409, self::OTHER_WEBSITES);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
        $this->assertSame([], ModuleLog::$activity);
    }

    public function testTheOnlyActiveWebsiteOfACustomerWithOtherWebsitesStaysAndTheServiceEnds(): void
    {
        // The customer's other websites, none of them this agency's, are inactive: CookiePal refuses both deletes.
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]))
            ->expect('DELETE', $this->deleteCustomerPath(), 409, self::OTHER_WEBSITES)
            ->expect('DELETE', $this->deleteDomainPath(), 400, self::ONLY_ACTIVE_DOMAIN);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
        $this->assertSame(
            [
                "CookiePal: service #101 is terminated, but CookiePal didn't delete its website example.com on "
                . 'customer ' . self::CUSTOMER . ", because it's the customer's only active website and the "
                . "customer has websites your agency doesn't manage. The website stays in CookiePal. Contact "
                . 'CookiePal support (support@cookiepal.io) if it should go.',
            ],
            ModuleLog::$activity
        );
    }

    /** @return array<string, array{list<string>}> */
    public static function websitesNoLongerLinkedToTheAgency(): array
    {
        return [
            'customer kept by another website' => [['example.com', 'second.com']],
            'customer with websites the agency does not manage' => [['example.com']],
        ];
    }

    /** @param list<string> $listed the agency's websites in the GET */
    #[DataProvider('websitesNoLongerLinkedToTheAgency')]
    public function testAWebsiteNoLongerLinkedToTheAgencyEndsTheService(array $listed): void
    {
        // The website moved to another agency between the GET and the delete.
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 200, $this->customerBody(array_map(
            fn (string $domainId): array => $this->domainItem($domainId),
            $listed
        )));
        if (count($listed) === 1) {
            $this->transport->expect('DELETE', $this->deleteCustomerPath(), 409, self::OTHER_WEBSITES);
        }
        $this->transport->expect('DELETE', $this->deleteDomainPath(), 401, self::NOT_LINKED);

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertNotNull($this->terminatedAt());
        $this->assertSame(
            [
                "CookiePal: service #101 is terminated, but CookiePal didn't delete its website example.com on "
                . 'customer ' . self::CUSTOMER . ", because it's no longer linked to your agency.",
            ],
            ModuleLog::$activity
        );
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function otherUnauthorizedAnswers(): array
    {
        return [
            'no key' => [['message' => 'Unauthorized'], 'CookiePal received no API key (401).'],
            'another customer' => [
                ['message' => 'Customer does not belong to this agency'],
                'CookiePal API error (401): Customer does not belong to this agency',
            ],
            'another message' => [
                ['message' => 'This website is not linked to your agency'],
                'CookiePal API error (401): This website is not linked to your agency',
            ],
        ];
    }

    /** @param array<string, string> $answer */
    #[DataProvider('otherUnauthorizedAnswers')]
    public function testAnyOtherUnauthorizedWebsiteDeleteLeavesTheServiceLive(array $answer, string $message): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('example.com'), $this->domainItem('second.com'),
            ]))
            ->expect('DELETE', $this->deleteDomainPath(), 401, $answer);

        $this->assertStringStartsWith($message, cookiepal_TerminateAccount($this->params()));
        $this->assertNull($this->terminatedAt());
        $this->assertSame([], ModuleLog::$activity);
    }

    public function testTerminateWithoutAWebsiteIsSuccess(): void
    {
        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertSame([], $this->transport->requests);
    }

    public function testTerminatingTwiceIsSuccess(): void
    {
        $this->seedService(terminatedAt: '2026-11-21 09:00:00');

        $this->assertSame('success', cookiepal_TerminateAccount($this->params()));
        $this->assertSame([], $this->transport->requests);
    }

    public function testTheTerminationTimeIsUtc(): void
    {
        $this->seedService();
        $this->transport
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]))
            ->expect('DELETE', $this->deleteCustomerPath(), 204);
        $before = ServiceStore::now();

        cookiepal_TerminateAccount($this->params());

        $this->assertGreaterThanOrEqual($before, (string) ($this->row()['terminated_at'] ?? ''));
        $this->assertLessThanOrEqual(ServiceStore::now(), (string) ($this->row()['terminated_at'] ?? ''));
    }
}
