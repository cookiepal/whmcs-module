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
        $this->seedService(100, 7, self::CUSTOMER, 'first.com', serverId: 3);
        $this->transport->expect('POST', '/external/domain', 201, $this->addedDomainBody('example.com'));

        $this->assertSame('success', cookiepal_CreateAccount($this->params(['serverid' => 3])));
        $this->assertSame(3, $this->row(101)['server_id'] ?? null);

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

    private const OTHER_SERVER_MESSAGE = "The email ada@example.com already has a CookiePal account through this "
        . "client's service #100, which is on another CookiePal server (WHMCS server #2), and this server's API key "
        . "couldn't use that account. If the two servers use different API keys, put the client's CookiePal products "
        . 'on the same server. Otherwise contact CookiePal support (support@cookiepal.io) with both service IDs.';

    public function testAServiceMovedToAnotherServerRecordStillSharesItsCustomer(): void
    {
        // Service 100's row says server 2 (it was moved, or server 2 holds the same key): this key owns its customer.
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'first.com', serverId: 2);
        $this->transport
            ->expect('GET', $this->customerPath(self::OTHER_CUSTOMER), 200, $this->customerBody(
                [$this->domainItem('first.com')],
                self::OTHER_CUSTOMER
            ))
            ->expect('POST', '/external/domain', 201, $this->addedDomainBody('example.com', self::OTHER_CUSTOMER));

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame(self::OTHER_CUSTOMER, $this->transport->requests[1]['json']['customerId'] ?? null);
        $this->assertSame(self::OTHER_CUSTOMER, $this->row(101)['customer_id'] ?? null);
        $this->assertSame(1, $this->row(101)['server_id'] ?? null);
    }

    /** @return array<string, array{int, array<string, string>}> */
    public static function customersThisKeyDoesNotHave(): array
    {
        return [
            "another agency's" => [401, ['message' => 'Customer does not belong to this agency']],
            'gone' => [404, ['message' => 'customer not found', 'code' => 'CUSTOMER_NOT_FOUND']],
        ];
    }

    /** @param array<string, string> $answer */
    #[DataProvider('customersThisKeyDoesNotHave')]
    public function testAServiceOnAnotherServerWhoseCustomerThisKeyDoesNotHaveStartsANewOne(
        int $status,
        array $answer
    ): void {
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'first.com', serverId: 2);
        $this->transport
            ->expect('GET', $this->customerPath(self::OTHER_CUSTOMER), $status, $answer)
            ->expect('POST', '/external/customer', 201, $this->createdCustomerBody());

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame(1, $this->row(101)['server_id'] ?? null);
        $this->assertSame(self::CUSTOMER, $this->row(101)['customer_id'] ?? null);
    }

    /** @param array<string, string> $answer */
    #[DataProvider('customersThisKeyDoesNotHave')]
    public function testAnEmailAlreadyUsedOnAnotherServerSaysSo(int $status, array $answer): void
    {
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'first.com', serverId: 2);
        $this->transport
            ->expect('GET', $this->customerPath(self::OTHER_CUSTOMER), $status, $answer)
            ->expect('POST', '/external/customer', 409, self::USER_EXISTS);

        $this->assertSame(self::OTHER_SERVER_MESSAGE, cookiepal_CreateAccount($this->params()));
        $this->assertNull($this->row(101));
    }

    public function testTheOtherServerIsNamedBeforeARecentTermination(): void
    {
        $this->seedService(99, 7, self::CUSTOMER, 'old.com', ServiceStore::now(-3600));
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'first.com', serverId: 2);
        $this->transport
            ->expect('GET', $this->customerPath(self::OTHER_CUSTOMER), 401, ['message' => 'Customer does not belong'])
            ->expect('POST', '/external/customer', 409, self::USER_EXISTS);

        $this->assertSame(self::OTHER_SERVER_MESSAGE, cookiepal_CreateAccount($this->params()));
        $this->assertSame(['GET', 'POST'], array_column($this->transport->requests, 'method'));
    }

    public function testAServiceWhmcsNoLongerHasStillSharesTheCustomerThisKeyOwns(): void
    {
        // Its row was left at server 0 when the server column was added; its customer is still this agency's.
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'first.com', serverId: 0);
        $this->transport
            ->expect('GET', $this->customerPath(self::OTHER_CUSTOMER), 200, $this->customerBody(
                [$this->domainItem('first.com')],
                self::OTHER_CUSTOMER
            ))
            ->expect('POST', '/external/domain', 201, $this->addedDomainBody('example.com', self::OTHER_CUSTOMER));

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame(self::OTHER_CUSTOMER, $this->transport->requests[1]['json']['customerId'] ?? null);
        $this->assertSame(self::OTHER_CUSTOMER, $this->row(101)['customer_id'] ?? null);
        $this->assertSame(1, $this->row(101)['server_id'] ?? null);
    }

    /** @param array<string, string> $answer */
    #[DataProvider('customersThisKeyDoesNotHave')]
    public function testAServiceWhmcsNoLongerHasIsNotCalledAnotherServer(int $status, array $answer): void
    {
        // There is no server to name, so the message says what happened to the service instead.
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'first.com', serverId: 0);
        $this->transport
            ->expect('GET', $this->customerPath(self::OTHER_CUSTOMER), $status, $answer)
            ->expect('POST', '/external/customer', 409, self::USER_EXISTS);

        $this->assertSame(
            "The email ada@example.com already has a CookiePal account through this client's service #100, which "
            . "WHMCS no longer has on a server, and this server's API key couldn't use that account. Contact "
            . 'CookiePal support (support@cookiepal.io) with both service IDs.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertSame(
            ['GET ' . $this->customerPath(self::OTHER_CUSTOMER), 'POST /external/customer'],
            $this->transport->sent()
        );
        $this->assertNull($this->row(101));
    }

    public function testAFailedReadOfTheOtherServersCustomerFailsTheCreate(): void
    {
        $this->seedService(100, 7, self::OTHER_CUSTOMER, 'first.com', serverId: 2);
        $this->transport
            ->expect('GET', $this->customerPath(self::OTHER_CUSTOMER), 500, ['message' => 'Internal server error']);

        $this->assertSame('CookiePal API error (500): Internal server error', cookiepal_CreateAccount($this->params()));
        $this->assertNull($this->row(101));
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

    public function testAReplayOnTheProductsPlanMakesNoPlanCall(): void
    {
        $this->transport->expect('POST', '/external/customer', 200, $this->createdCustomerBody());

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));

        $this->assertSame(['POST /external/customer'], $this->transport->sent());
        $this->assertSame(self::CUSTOMER, $this->row()['customer_id'] ?? null);
    }

    /** @return array<string, array{0: string|null}> */
    public static function replayedPlans(): array
    {
        return [
            'another of the four' => ['starter'],
            'one outside the four' => ['other'],
            'none' => [null],
        ];
    }

    #[DataProvider('replayedPlans')]
    public function testAReplayOnAnotherPlanIsMovedToTheProductsPlan(?string $replayed): void
    {
        $body = $this->createdCustomerBody();
        $body['domain']['plan'] = $replayed;
        $this->transport
            ->expect('POST', '/external/customer', 200, $body)
            ->expect('PUT', $this->domainPath('/plan'), 200, self::PLAN_SET);

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));

        $this->assertSame(['POST /external/customer', 'PUT ' . $this->domainPath('/plan')], $this->transport->sent());
        $this->assertSame(['plan' => 'growth'], $this->transport->requests[1]['json']);
        $this->assertSame(self::CUSTOMER, $this->row()['customer_id'] ?? null);
    }

    public function testAnAddedWebsiteReplayedOnAnotherPlanIsMovedToTheProductsPlan(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport
            ->expect('POST', '/external/domain', 200, $this->addedDomainBody('example.com', plan: 'business'))
            ->expect('PUT', $this->domainPath('/plan'), 200, self::PLAN_SET);

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));

        $this->assertSame(['POST /external/domain', 'PUT ' . $this->domainPath('/plan')], $this->transport->sent());
        $this->assertSame(['plan' => 'growth'], $this->transport->requests[1]['json']);
        $this->assertSame('example.com', $this->row(101)['domain_id'] ?? null);
    }

    public function testANewWebsiteIsTakenOnThePlanSent(): void
    {
        $this->transport->expect('POST', '/external/customer', 201, $this->createdCustomerBody(plan: 'starter'));

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));

        $this->assertSame(['POST /external/customer'], $this->transport->sent());
    }

    /** @return array<string, array{0: int, 1: array<string, string>, 2: string}> */
    public static function failedPlanCalls(): array
    {
        return [
            'a plan change in progress' => [
                409,
                ['message' => 'conflict', 'code' => 'PLAN_CHANGE_CONFLICT'],
                'Another plan change for this website was in progress. Try again in a minute.',
            ],
            'a plan the customer pays for' => [
                409,
                ['message' => 'conflict', 'code' => 'PLAN_NOT_MANAGED_BY_AGENCY'],
                "The customer pays for this website's plan in CookiePal themselves, so WHMCS can't change it.",
            ],
            'a server error' => [
                500,
                ['message' => 'Internal server error'],
                'CookiePal API error (500): Internal server error',
            ],
            'no answer' => [0, [], "Couldn't reach CookiePal (curl error 28): Operation timed out"],
        ];
    }

    /** @param array<string, string> $answer */
    #[DataProvider('failedPlanCalls')]
    public function testAFailedPlanCallAfterAReplaySavesNothingAndCreateAgainRetriesIt(
        int $status,
        array $answer,
        string $message
    ): void {
        $this->transport->expect('POST', '/external/customer', 200, $this->createdCustomerBody(plan: 'starter'));
        if ($status === 0) {
            $this->transport->expectNetworkError('PUT', $this->domainPath('/plan'));
        } else {
            $this->transport->expect('PUT', $this->domainPath('/plan'), $status, $answer);
        }

        // The same message Create gives when the plan call fails for a domain found on the customer.
        $this->assertSame($message, cookiepal_CreateAccount($this->params()));
        $this->assertNull($this->row());

        $this->transport
            ->expect('POST', '/external/customer', 200, $this->createdCustomerBody(plan: 'starter'))
            ->expect('PUT', $this->domainPath('/plan'), 200, self::PLAN_SET);

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame(
            [self::KEY, null, self::KEY, null],
            array_map(fn (array $r): mixed => $r['json']['idempotencyKey'] ?? null, $this->transport->requests)
        );
        $this->assertSame(['plan' => 'growth'], $this->transport->requests[3]['json']);
        $this->assertSame(self::CUSTOMER, $this->row()['customer_id'] ?? null);
        $this->assertSame('example.com', $this->row()['domain_id'] ?? null);
    }

    public function testAFailedPlanCallAfterAReplayOnAnAddedWebsiteSavesNothing(): void
    {
        // A 404 here fails the Create: it must not be read as "the customer is gone", which starts a new customer.
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport
            ->expect('POST', '/external/domain', 200, $this->addedDomainBody('example.com', plan: 'free'))
            ->expect('PUT', $this->domainPath('/plan'), 404, ['message' => 'domain not found']);

        $this->assertSame('CookiePal API error (404): domain not found', cookiepal_CreateAccount($this->params()));
        $this->assertSame(['POST /external/domain', 'PUT ' . $this->domainPath('/plan')], $this->transport->sent());
        $this->assertNull($this->row(101));

        $this->transport
            ->expect('POST', '/external/domain', 200, $this->addedDomainBody('example.com', plan: 'free'))
            ->expect('PUT', $this->domainPath('/plan'), 200, self::PLAN_SET);

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame(self::KEY, $this->transport->requests[2]['json']['idempotencyKey'] ?? null);
        $this->assertSame('example.com', $this->row(101)['domain_id'] ?? null);
    }

    public function testAReplayOnAServiceCreatedAgainAfterTerminateKeepsItsKeyUntilItIsSaved(): void
    {
        $this->seedService(101, 7, self::OTHER_CUSTOMER, 'old.com', '2026-11-20 09:30:00');
        $this->transport
            ->expect('POST', '/external/customer', 200, $this->createdCustomerBody(plan: 'starter'))
            ->expect('PUT', $this->domainPath('/plan'), 500, ['message' => 'Internal server error'])
            ->expect('POST', '/external/customer', 200, $this->createdCustomerBody(plan: 'starter'))
            ->expect('PUT', $this->domainPath('/plan'), 200, self::PLAN_SET);

        $this->assertSame('CookiePal API error (500): Internal server error', cookiepal_CreateAccount($this->params()));
        // Still the terminated row, so the next Create builds the same key and replays again.
        $this->assertSame('2026-11-20 09:30:00', $this->terminatedAt());
        $this->assertSame(self::OTHER_CUSTOMER, $this->row()['customer_id'] ?? null);

        $this->assertSame('success', cookiepal_CreateAccount($this->params()));
        $this->assertSame(
            [self::KEY . '-20261120093000', self::KEY . '-20261120093000'],
            [
                $this->transport->requests[0]['json']['idempotencyKey'] ?? null,
                $this->transport->requests[2]['json']['idempotencyKey'] ?? null,
            ]
        );
        $this->assertCount(1, FakeDatabase::$tables[ServiceStore::TABLE]);
        $this->assertSame(self::CUSTOMER, $this->row()['customer_id'] ?? null);
        $this->assertNull($this->terminatedAt());
    }

    /** @return array<string, array{0: string}> */
    public static function plansOfATakenWebsite(): array
    {
        return ["the product's plan" => ['growth'], 'another plan' => ['starter']];
    }

    #[DataProvider('plansOfATakenWebsite')]
    public function testAReplayedWebsiteAnotherServiceTookMeanwhileIsRefused(string $replayed): void
    {
        // Service 101's first answer was lost; service 102 then ordered the same domain and got the website.
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->seedService(102, 7, self::CUSTOMER, 'example.com', createdAt: '2026-11-19 10:00:00');
        $this->transport
            ->expect('POST', '/external/domain', 200, $this->addedDomainBody('example.com', plan: $replayed));

        $this->assertSame(
            'This website already belongs to service #102. Terminate that service first, or cancel this order.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertSame(['POST /external/domain'], $this->transport->sent());
        $this->assertNull($this->row(101));
    }

    public function testAReplayedNewCustomersWebsiteAnotherServiceHasIsRefused(): void
    {
        $this->seedService(102, 8, self::CUSTOMER, 'example.com');
        $this->transport->expect('POST', '/external/customer', 200, $this->createdCustomerBody(plan: 'starter'));

        $this->assertSame(
            'This website already belongs to service #102. Terminate that service first, or cancel this order.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertSame(['POST /external/customer'], $this->transport->sent());
        $this->assertNull($this->row(101));
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

    public function testAnInactiveWebsiteThatIsNotScheduledForDeletionIsRefusedWithItsOwnMessage(): void
    {
        // Inactive isn't always being removed, and nothing the module does reactivates it.
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport
            ->expect('POST', '/external/domain', 409, self::DOMAIN_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([
                $this->domainItem('first.com'),
                $this->domainItem('example.com', ['status' => 'inactive']),
            ]));

        $this->assertSame(
            "The website example.com is already on this client's CookiePal customer, but it's inactive in CookiePal, "
            . "so Create can't use it. Contact CookiePal support (support@cookiepal.io) to reactivate or remove it, "
            . 'then run Create again.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row(101));
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
            'inactive and scheduled' => [['status' => 'inactive', 'scheduledDeletion' => '2026-11-26T10:00:00.000Z']],
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

    /**
     * GET /external/customers/{c} for a customer scheduled for deletion.
     *
     * @param list<array<string, mixed>> $domains
     *
     * @return array<string, mixed>
     */
    private function scheduledCustomerBody(array $domains): array
    {
        return $this->customerBody($domains) + ['scheduledDeletion' => '2026-11-27T10:00:00Z'];
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
            ->expect('GET', $this->customerPath(), 200, $this->scheduledCustomerBody([
                $this->domainItem('first.com', ['scheduledDeletion' => '2026-11-27T10:00:00Z']),
                $this->domainItem('old.com', ['status' => 'inactive']),
            ]));

        $this->assertSame(self::stillRemoving(100, $terminatedAt), cookiepal_CreateAccount($this->params()));
        $this->assertNull($this->row(101));
    }

    public function testAnEmailOnACustomerWhoseWebsitesAreAllInactiveSaysToWait(): void
    {
        // Deleting a customer dates the customer and makes its websites inactive without dating them.
        $terminatedAt = ServiceStore::now(-3600);
        $this->seedService(100, 7, self::CUSTOMER, 'first.com', $terminatedAt);
        $this->transport
            ->expect('POST', '/external/customer', 409, self::USER_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->scheduledCustomerBody([
                $this->domainItem('first.com', ['status' => 'inactive']),
                $this->domainItem('shop.example.org', ['status' => 'inactive']),
            ]));

        $this->assertSame(self::stillRemoving(100, $terminatedAt), cookiepal_CreateAccount($this->params()));
    }

    public function testAnEmailOnAScheduledCustomerThatStillListsAnActiveWebsiteSaysToWait(): void
    {
        // The customer's own date decides, whatever its websites show.
        $terminatedAt = ServiceStore::now(-3600);
        $this->seedService(100, 7, self::CUSTOMER, 'first.com', $terminatedAt);
        $this->transport
            ->expect('POST', '/external/customer', 409, self::USER_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->scheduledCustomerBody([
                $this->domainItem('first.com'),
            ]));

        $this->assertSame(self::stillRemoving(100, $terminatedAt), cookiepal_CreateAccount($this->params()));
    }

    /** @return array<string, array{list<array<string, mixed>>}> */
    public static function websitesOfACustomerKeptByOthers(): array
    {
        return [
            'none listed' => [[]],
            'only its own, being removed' => [[
                ['domainId' => 'first.com', 'status' => 'inactive', 'scheduledDeletion' => '2026-11-27T10:00:00Z'],
            ]],
            'only inactive ones' => [[['domainId' => 'first.com', 'status' => 'inactive']]],
        ];
    }

    /** @param list<array<string, mixed>> $domains */
    #[DataProvider('websitesOfACustomerKeptByOthers')]
    public function testAnEmailOnACustomerKeptByWebsitesTheAgencyDoesNotManageSaysSo(array $domains): void
    {
        // Terminate removed only first.com: the customer has websites this agency doesn't manage, which the
        // GET doesn't list, and isn't scheduled for deletion. Waiting a day changes nothing.
        $this->seedService(100, 7, self::CUSTOMER, 'first.com', ServiceStore::now(-3600));
        $this->transport
            ->expect('POST', '/external/customer', 409, self::USER_EXISTS)
            ->expect('GET', $this->customerPath(), 200, $this->customerBody($domains));

        $this->assertSame(
            "The email ada@example.com belongs to the CookiePal customer of this client's service #100, which "
            . 'CookiePal kept when that service was terminated because it has other websites. Contact CookiePal '
            . 'support (support@cookiepal.io) with both service IDs.',
            cookiepal_CreateAccount($this->params())
        );
        $this->assertNull($this->row(101));
    }

    public function testAnEmailOnARecentCustomerThisKeyDoesNotOwnGetsThePlainMessage(): void
    {
        // Service 100 was on another agency's key (its server record since changed): a 401 says nothing here.
        $this->seedService(100, 7, self::CUSTOMER, 'first.com', ServiceStore::now(-3600));
        $this->transport
            ->expect('POST', '/external/customer', 409, self::USER_EXISTS)
            ->expect('GET', $this->customerPath(), 401, ['message' => 'Customer does not belong to this agency']);

        $this->assertStringStartsWith(
            'The email ada@example.com already has its own CookiePal account.',
            cookiepal_CreateAccount($this->params())
        );
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
