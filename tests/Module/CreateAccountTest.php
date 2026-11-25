<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use CookiePal\Tests\Support\FakeDatabase;
use CookiePal\Tests\Support\ModuleLog;
use CookiePal\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WHMCS\Config\Setting;
use WHMCS\Module\Server\CookiePal\Module;
use WHMCS\Module\Server\CookiePal\ServiceStore;
use WHMCS\Module\Server\CookiePal\Transport;

final class CreateAccountTest extends ModuleTestCase
{
    /** whmcs-, sha256('billing.example.net') to 16 hex, the service ID. */
    private const KEY = 'whmcs-9671d0febcefbe9b-101';
    private const ELSEWHERE = [
        'message' => 'Domain is already registered to another account',
        'code' => 'DOMAIN_REGISTERED_ELSEWHERE',
    ];
    private const USER_EXISTS = ['message' => 'user already exists', 'code' => 'USER_EXISTS'];
    private const DOMAIN_EXISTS = ['message' => 'domain already exists', 'code' => 'DOMAIN_EXISTS'];
    private const PLAN_SET = [
        'customerId' => self::CUSTOMER,
        'domainId' => 'example.com',
        'plan' => 'growth',
        'changed' => true,
    ];

    public function testANewClientGetsANewCustomerOnTheProductsPlan(): void
    {
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));

        $this->assertSame([
            'customerName' => 'Ada Lovelace',
            'customerEmail' => 'ada@example.com',
            'domainId' => 'example.com',
            'plan' => 'growth',
            'idempotencyKey' => self::KEY,
        ], $this->transport->requests[0]['json']);
        $row = $this->row();
        $this->assertNotNull($row);
        $this->assertSame(7, $row['client_id']);
        $this->assertSame(self::CUSTOMER, $row['customer_id']);
        $this->assertSame('example.com', $row['domain_id']);
        $this->assertNull($row['terminated_at']);
    }

    public function testTheCompanyNameIsTheCustomerName(): void
    {
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        cookiepal_CreateAccount($this->params(['clientsdetails' => ['companyname' => 'Analytical Engines Ltd']]));

        $this->assertSame('Analytical Engines Ltd', $this->transport->requests[0]['json']['customerName']);
    }

    public function testWithoutAnyNameTheEmailIsTheCustomerName(): void
    {
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        cookiepal_CreateAccount($this->params(['clientsdetails' => ['firstname' => ' ', 'lastname' => '']]));

        $this->assertSame('ada@example.com', $this->transport->requests[0]['json']['customerName']);
    }

    public function testTheStoredDomainIsTheOneTheApiReturned(): void
    {
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody('shop.example.com'));

        cookiepal_CreateAccount($this->params(['domain' => 'Shop.Example.com']));

        $this->assertSame('shop.example.com', $this->transport->requests[0]['json']['domainId']);
        $this->assertSame('shop.example.com', $this->row()['domain_id'] ?? null);
    }

    public function testASecondServiceOfTheSameClientAddsADomainToItsCustomer(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport->expect('POST', '/external/domain', 201, $this->addedDomainBody('example.com'));

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));

        $this->assertSame(
            [
                'customerId' => self::CUSTOMER,
                'domain' => ['domainId' => 'example.com', 'plan' => 'growth'],
                'idempotencyKey' => self::KEY,
            ],
            $this->transport->requests[0]['json']
        );
        $this->assertSame(self::CUSTOMER, $this->row(101)['customer_id'] ?? null);
        $this->assertSame('example.com', $this->row(101)['domain_id'] ?? null);
    }

    public function testALongTerminatedServiceOfTheClientDoesNotCount(): void
    {
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'first.com', '2020-01-01 09:00:00');
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
    }

    public function testARetryAfterALostAnswerSendsTheSameKeyAndTheReplayCompletesIt(): void
    {
        $this->transport
            ->expectNetworkError('POST', '/external/customer')
            ->expect('POST', '/external/customer', 200, $this->createdCustomerBody());

        $this->assertSame(
            "Couldn't reach CookiePal (curl error 28): Operation timed out",
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row());
        $this->assertSame('success', cookiepal_CreateAccount($this->params()));

        $this->assertSame(
            [self::KEY, self::KEY],
            array_map(fn (array $r): mixed => $r['json']['idempotencyKey'] ?? null, $this->transport->requests)
        );
        $this->assertSame(self::CUSTOMER, $this->row()['customer_id'] ?? null);
        $this->assertSame('example.com', $this->row()['domain_id'] ?? null);
        $this->assertNull($this->terminatedAt());
    }

    public function testAReplayedWebsiteIsSavedLikeANewOneAndNothingIsSentAgain(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport->expect('POST', '/external/domain', 200, $this->addedDomainBody('example.com'));

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));

        $this->assertSame(['POST /external/domain'], $this->transport->sent());
        $this->assertSame(self::KEY, $this->transport->requests[0]['json']['idempotencyKey'] ?? null);
        $this->assertSame(self::CUSTOMER, $this->row(101)['customer_id'] ?? null);
        $this->assertSame('example.com', $this->row(101)['domain_id'] ?? null);
    }

    public function testTheKeyIsInTheModuleLogUnmasked(): void
    {
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        cookiepal_CreateAccount($this->params());

        $call = ModuleLog::$calls[0];
        $this->assertSame([self::API_KEY], $call['replaceVars']);
        $this->assertStringContainsString('"idempotencyKey":"' . self::KEY . '"', ModuleLog::asShown($call));
    }

    public function testWithoutASystemUrlNoKeyIsSent(): void
    {
        Setting::$values = [];
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertArrayNotHasKey('idempotencyKey', $this->transport->requests[0]['json']);
    }

    public function testCreatingAgainAfterTerminateSendsANewKey(): void
    {
        $this->seedService(101, 7, self::OTHER_CUSTOMER, 'example.com', '2026-11-20 09:30:00');
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame(self::KEY . '-20261120093000', $this->transport->requests[0]['json']['idempotencyKey']);
    }

    public function testARetryOnAServiceThatHasItsWebsiteSucceedsWithoutACall(): void
    {
        $this->seedService();

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame([], $this->transport->requests);
    }

    public function testATerminatedServiceIsCreatedAgain(): void
    {
        $this->seedService(101, 7, self::OTHER_CUSTOMER, 'example.com', '2020-01-01 09:00:00');
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));

        $this->assertCount(1, FakeDatabase::$tables[ServiceStore::TABLE]);
        $this->assertSame(self::CUSTOMER, $this->row()['customer_id'] ?? null);
        $this->assertNull($this->row()['terminated_at'] ?? null);
    }

    public function testAnEmptyDomainIsRefusedBeforeAnyCall(): void
    {
        $result = cookiepal_CreateAccount($this->params(['domain' => '  ']));

        $this->assertSame("Enter the customer's website domain on the service first.", $result);
        $this->assertSame([], $this->transport->requests);
        $this->assertNull($this->row());
    }

    public function testADomainThatIsNotAWebsiteIsRefusedBeforeAnyCall(): void
    {
        $result = cookiepal_CreateAccount($this->params(['domain' => 'exa mple.com']));

        $this->assertStringStartsWith("The service's Domain field isn't a website domain", $result);
        $this->assertSame([], $this->transport->requests);
    }

    public function testAnUnknownPlanIsRefusedBeforeAnyCall(): void
    {
        $result = cookiepal_CreateAccount($this->params(['configoption1' => 'platinum']));

        $this->assertSame(
            "Choose a CookiePal plan (free, starter, growth or business) in the product's Module Settings.",
            $result
        );
        $this->assertSame([], $this->transport->requests);
    }

    public function testAConfigurableOptionNamedPlanOverridesTheProductsPlan(): void
    {
        $this->transport->expect(
            'POST',
            '/external/customer',
            201,
            $this->createdCustomerBody('example.com', 'business')
        );

        cookiepal_CreateAccount($this->params(['configoptions' => ['Plan' => 'Business']]));

        $this->assertSame('business', $this->transport->requests[0]['json']['plan']);
    }

    public function testAClientWithoutEmailIsRefused(): void
    {
        $result = cookiepal_CreateAccount($this->params(['clientsdetails' => ['email' => '']]));

        $this->assertSame('The client has no email address. Add one to the client profile first.', $result);
        $this->assertSame([], $this->transport->requests);
    }

    public function testADomainAlreadyOnTheSameCustomerIsCompletedOnTheProductsPlan(): void
    {
        // The first Create added the domain, but its answer never reached WHMCS.
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport
            ->expect('POST', '/external/domain', 409, self::DOMAIN_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('first.com'),
                $this->domainItem('example.com', ['plan' => 'free']),
            ]))
            ->expect('PUT', $this->domainPath('/plan'), 200, self::PLAN_SET);

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame(['plan' => 'growth'], $this->transport->requests[2]['json']);
        $this->assertSame('example.com', $this->row(101)['domain_id'] ?? null);
    }

    public function testADomainWithoutAStatusIsActive(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $item = $this->domainItem('example.com');
        unset($item['status']);
        $this->transport
            ->expect('POST', '/external/domain', 409, self::DOMAIN_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('first.com'), $item]))
            ->expect('PUT', $this->domainPath('/plan'), 200, self::PLAN_SET);

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame('example.com', $this->row(101)['domain_id'] ?? null);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function domainsBeingRemoved(): array
    {
        return [
            'scheduled for deletion' => [['scheduledDeletion' => '2026-11-26T10:00:00.000Z']],
            'inactive' => [['status' => 'inactive']],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('domainsBeingRemoved')]
    public function testADomainBeingRemovedFromTheCustomerIsNotReused(array $overrides): void
    {
        // The client terminated example.com earlier today, and orders it again.
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport
            ->expect('POST', '/external/domain', 409, self::DOMAIN_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('first.com'),
                $this->domainItem('example.com', $overrides),
            ]));

        $this->assertSame(
            "The website example.com is being removed from CookiePal, so it can't be added again yet. "
            . 'Try again tomorrow, or contact CookiePal support (support@cookiepal.io).',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row(101));
    }

    public function testASecondServiceForTheSameWebsiteIsRefused(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'example.com');
        $this->transport
            ->expect('POST', '/external/domain', 409, self::DOMAIN_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]));

        $this->assertSame(
            'This website already belongs to service #100. Terminate that service first, or cancel this order.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row(101));
    }

    public function testAPlanConflictOnAConfirmedDomainSavesNothing(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport
            ->expect('POST', '/external/domain', 409, self::DOMAIN_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]))
            ->expect(
                'PUT',
                $this->domainPath('/plan'),
                409,
                ['message' => 'conflict', 'code' => 'PLAN_NOT_MANAGED_BY_AGENCY']
            );

        $this->assertSame(
            "The customer pays for this website's plan in CookiePal themselves, so WHMCS can't change it.",
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row(101));
    }

    public function testADomainAlreadyExistsThatTheCustomerDoesNotHaveIsAnError(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport
            ->expect('POST', '/external/domain', 409, self::DOMAIN_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('first.com')]));

        $this->assertSame(
            'CookiePal API error (409): domain already exists [DOMAIN_EXISTS]',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row(101));
    }

    public function testADomainRegisteredElsewhereIsAClearMessage(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport->expect('POST', '/external/domain', 409, self::ELSEWHERE);

        $this->assertSame(
            'The domain example.com is already registered to another CookiePal account. The customer can remove it '
            . 'from that account, or ask CookiePal support (support@cookiepal.io) to move it to your agency.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row(101));
    }

    public function testA409WithoutACodeIsNotMatchedOnItsText(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport->expect('POST', '/external/domain', 409, ['message' => 'domain already exists']);

        $this->assertSame('CookiePal API error (409): domain already exists', cookiepal_CreateAccount($this->params()));
    }

    public function testACustomerThatNoLongerExistsIsReplacedByANewOne(): void
    {
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'first.com');
        $this->transport
            ->expect(
                'POST',
                '/external/domain',
                404,
                ['message' => 'customer not found', 'code' => 'CUSTOMER_NOT_FOUND']
            )
            ->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame(self::CUSTOMER, $this->row(101)['customer_id'] ?? null);
    }

    public function testAnEmailWithItsOwnAccountIsAClearMessage(): void
    {
        $this->transport->expect('POST', '/external/customer', 409, self::USER_EXISTS);

        $this->assertSame(
            'The email ada@example.com already has its own CookiePal account. Ask CookiePal support '
            . '(support@cookiepal.io) to link that account to your agency, then run Create again.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row());
        $this->assertSame(['POST /external/customer'], $this->transport->sent());
    }

    public function testANewCustomerForADomainRegisteredElsewhereIsAClearMessage(): void
    {
        $this->transport->expect('POST', '/external/customer', 409, self::ELSEWHERE);

        $this->assertStringStartsWith(
            'The domain example.com is already registered to another CookiePal account.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row());
        $this->assertSame(['POST /external/customer'], $this->transport->sent());
    }

    public function testADomainOnACustomerWithoutALiveServiceIsNeverAttached(): void
    {
        // The agency's customer for example.com has no live WHMCS service here: made outside WHMCS,
        // or another client's service was terminated. This client ordering that domain mustn't get it.
        $this->seedService(55, 9, self::CUSTOMER, 'example.com', ServiceStore::now(-3600));
        $this->transport->expect('POST', '/external/customer', 409, self::ELSEWHERE);

        $this->assertStringStartsWith(
            'The domain example.com is already registered to another CookiePal account.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row(101));
        $this->assertSame(['POST /external/customer'], $this->transport->sent());
    }

    private static function stillRemoving(int $serviceId, string $terminatedAt): string
    {
        return "This client's CookiePal service #" . $serviceId . ' was terminated on ' . $terminatedAt . ' UTC, and '
            . 'CookiePal is still removing it, which takes about a day. Run Create again after that, or contact '
            . 'CookiePal support (support@cookiepal.io).';
    }

    /** @return array<string, array{int}> */
    public static function agesWithinTheRemovalWindow(): array
    {
        return ['an hour' => [3600], '47 hours' => [47 * 3600]];
    }

    #[DataProvider('agesWithinTheRemovalWindow')]
    public function testOrderingAgainWithinTwoDaysOfTerminateSaysTheOldOneIsStillBeingRemoved(int $age): void
    {
        $terminatedAt = ServiceStore::now(-$age);
        $this->seedService(100, 7, self::CUSTOMER, 'example.com', $terminatedAt);
        $this->transport->expect('POST', '/external/customer', 409, self::ELSEWHERE);

        $this->assertSame(self::stillRemoving(100, $terminatedAt), cookiepal_CreateAccount($this->params()));
        $this->assertNull($this->row(101));
        $this->assertSame(['POST /external/customer'], $this->transport->sent());
    }

    /** @return array<string, array{array<string, string>, int, non-empty-string}> */
    public static function conflictsAfterTheRemovalWindow(): array
    {
        $domain = 'The domain example.com is already registered to another CookiePal account.';
        $email = 'The email ada@example.com already has its own CookiePal account.';

        return [
            'the domain, 49 hours' => [self::ELSEWHERE, 49 * 3600, $domain],
            'the domain, 3 days' => [self::ELSEWHERE, 3 * 86400, $domain],
            'the email, 49 hours' => [self::USER_EXISTS, 49 * 3600, $email],
            'the email, 3 days' => [self::USER_EXISTS, 3 * 86400, $email],
        ];
    }

    /**
     * @param array<string, string> $conflict
     * @param non-empty-string $message
     */
    #[DataProvider('conflictsAfterTheRemovalWindow')]
    public function testATerminationOlderThanTwoDaysGivesThePlainMessage(
        array $conflict,
        int $age,
        string $message
    ): void {
        $this->seedService(100, 7, self::CUSTOMER, 'example.com', ServiceStore::now(-$age));
        $this->transport->expect('POST', '/external/customer', 409, $conflict);

        $this->assertStringStartsWith($message, cookiepal_CreateAccount($this->params()));
        $this->assertNull($this->row(101));
        $this->assertSame(['POST /external/customer'], $this->transport->sent());
    }

    public function testARecentTerminationOfAnotherWebsiteGivesThePlainMessage(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'other.com', ServiceStore::now(-3600));
        $this->transport->expect('POST', '/external/customer', 409, self::ELSEWHERE);

        $this->assertStringStartsWith(
            'The domain example.com is already registered to another CookiePal account.',
            cookiepal_CreateAccount($this->params())
        );
    }

    public function testAddingAWebsiteAfterARecentTerminationOfAnotherGivesThePlainMessage(): void
    {
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'other.com', ServiceStore::now(-3600));
        $this->seedService(102, 7, self::CUSTOMER, 'second.com');
        $this->transport->expect('POST', '/external/domain', 409, self::ELSEWHERE);

        $this->assertStringStartsWith(
            'The domain example.com is already registered to another CookiePal account.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertSame(['POST /external/domain'], $this->transport->sent());
    }

    public function testAnEmailOnACustomerBeingRemovedSaysToWait(): void
    {
        $terminatedAt = ServiceStore::now(-3600);
        $this->seedService(100, 7, self::CUSTOMER, 'first.com', $terminatedAt);
        $this->transport
            ->expect('POST', '/external/customer', 409, self::USER_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('first.com', ['scheduledDeletion' => '2026-11-27T10:00:00Z']),
                $this->domainItem('old.com', ['status' => 'inactive']),
            ]));

        $this->assertSame(self::stillRemoving(100, $terminatedAt), cookiepal_CreateAccount($this->params()));
        $this->assertNull($this->row(101));
    }

    public function testAnEmailOnACustomerAlreadyGoneSaysToWait(): void
    {
        $terminatedAt = ServiceStore::now(-3600);
        $this->seedService(100, 7, self::CUSTOMER, 'first.com', $terminatedAt);
        $this->transport
            ->expect('POST', '/external/customer', 409, self::USER_EXISTS)
            ->expect(
                'GET',
                $this->customerPath(),
                404,
                ['message' => 'customer not found', 'code' => 'CUSTOMER_NOT_FOUND']
            );

        $this->assertSame(self::stillRemoving(100, $terminatedAt), cookiepal_CreateAccount($this->params()));
    }

    public function testAnEmailOnACustomerKeptForItsOtherWebsitesSaysSo(): void
    {
        // Terminating service 100 removed only first.com: the customer has another website, so its user stays.
        $this->seedService(100, 7, self::CUSTOMER, 'first.com', ServiceStore::now(-3600));
        $this->transport
            ->expect('POST', '/external/customer', 409, self::USER_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('first.com', ['scheduledDeletion' => '2026-11-27T10:00:00Z']),
                $this->domainItem('shop.example.org'),
            ]));

        $this->assertSame(
            "The email ada@example.com belongs to the CookiePal customer of this client's service #100, which "
            . 'CookiePal kept when that service was terminated because it has other websites. Contact CookiePal '
            . 'support (support@cookiepal.io) with both service IDs.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row(101));
    }

    public function testAddingADomainTheClientTerminatedOnAnotherCustomerSaysItIsBeingRemoved(): void
    {
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'example.com', ServiceStore::now(-3600));
        $this->seedService(102, 7, self::CUSTOMER, 'second.com');
        $this->transport->expect('POST', '/external/domain', 409, self::ELSEWHERE);

        $this->assertStringStartsWith(
            "This client's CookiePal service #100 was terminated on",
            cookiepal_CreateAccount($this->params())
        );
    }

    public function testARefusedKeyIsReported(): void
    {
        $this->transport->expect('POST', '/external/customer', 403, ['message' => 'Forbidden']);

        $this->assertStringStartsWith('CookiePal refused the API key (403).', cookiepal_CreateAccount($this->params()));
    }

    public function testAnInvalidPlanAnswerIsReported(): void
    {
        $this->transport->expect('POST', '/external/customer', 400, ['message' => 'invalid parameter value: plan']);

        $this->assertSame(
            'CookiePal API error (400): invalid parameter value: plan',
            cookiepal_CreateAccount($this->params())
        );
    }

    public function testATimeoutIsReportedAndTheCreateIsNotRetried(): void
    {
        $this->transport->expectNetworkError('POST', '/external/customer');

        $this->assertSame(
            "Couldn't reach CookiePal (curl error 28): Operation timed out",
            cookiepal_CreateAccount($this->params())
        );
        $this->assertCount(1, $this->transport->requests);
        $this->assertNull($this->row());
    }

    public function testAnAnswerWithoutADomainIdIsAnError(): void
    {
        $this->transport->expect('POST', '/external/customer', 201, ['customerId' => self::CUSTOMER, 'domain' => []]);

        $this->assertSame('CookiePal returned no domainId.', cookiepal_CreateAccount($this->params()));
        $this->assertNull($this->row());
    }

    public function testAnAnswerWithoutACustomerIdIsAnError(): void
    {
        $this->transport->expect('POST', '/external/customer', 201, ['domain' => ['domainId' => 'example.com']]);

        $this->assertSame('CookiePal returned no customerId.', cookiepal_CreateAccount($this->params()));
        $this->assertNull($this->row());
    }

    public function testIdsThatCannotBeSavedAreKeptInTheActivityLog(): void
    {
        $body = (string) json_encode($this->createdCustomerBody());
        Module::useTransport(new class ($body) implements Transport {
            public function __construct(private readonly string $body)
            {
            }

            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                unset(FakeDatabase::$tables[ServiceStore::TABLE]);

                return ['status' => 201, 'body' => $this->body];
            }
        });

        $result = cookiepal_CreateAccount($this->params());

        $this->assertSame('CookiePal module error: Base table or view not found: mod_cookiepal_services', $result);
        $this->assertSame(
            ['CookiePal: service #101 got CookiePal customer ' . self::CUSTOMER . ' and website example.com, '
                . "but the module couldn't save them: Base table or view not found: mod_cookiepal_services"],
            ModuleLog::$activity
        );
    }
}
