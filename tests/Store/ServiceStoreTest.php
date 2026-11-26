<?php

declare(strict_types=1);

namespace CookiePal\Tests\Store;

use CookiePal\Tests\Support\FakeDatabase;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\CookiePal\ServiceStore;

/** The mod_cookiepal_services table, through the fake Capsule. */
final class ServiceStoreTest extends TestCase
{
    private ServiceStore $store;

    protected function setUp(): void
    {
        FakeDatabase::reset();
        ServiceStore::resetForTests();
        $this->store = new ServiceStore();
    }

    /** @param array<string, mixed> $row */
    private function seed(array $row): void
    {
        $this->store->find(0);
        FakeDatabase::seed(
            ServiceStore::TABLE,
            $row + ['server_id' => 1, 'created_at' => '2026-11-20 10:00:00', 'terminated_at' => null]
        );
    }

    public function testTheTableIsCreatedOnFirstUseWithItsColumns(): void
    {
        $this->assertArrayNotHasKey(ServiceStore::TABLE, FakeDatabase::$tables);

        $this->assertNull($this->store->find(1));
        $this->store->find(2);

        $this->assertSame(1, FakeDatabase::$createCalls);
        $columns = [];
        foreach (FakeDatabase::$columns[ServiceStore::TABLE] as $column) {
            $columns[$column['name']] = [$column['type'], $column['arguments'], $column['modifiers']];
        }
        $this->assertSame([
            'service_id' => ['unsignedInteger', [], ['primary']],
            'server_id' => ['unsignedInteger', [], []],
            'client_id' => ['unsignedInteger', [], ['index']],
            'customer_id' => ['string', [64], ['index']],
            'domain_id' => ['string', [255], []],
            'created_at' => ['dateTime', [], []],
            'terminated_at' => ['dateTime', [], ['nullable']],
        ], $columns);
    }

    public function testAnExistingTableIsNotCreatedAgain(): void
    {
        FakeDatabase::$tables[ServiceStore::TABLE] = [];

        $this->store->find(1);

        $this->assertSame(0, FakeDatabase::$createCalls);
    }

    public function testATableAnotherRequestCreatedFirstIsUsed(): void
    {
        FakeDatabase::$createRace = true;

        $this->assertNull($this->store->find(1));
        $this->assertArrayHasKey(ServiceStore::TABLE, FakeDatabase::$tables);
    }

    public function testSavesFindsAndTerminatesInUtc(): void
    {
        $before = gmdate(ServiceStore::TIME_FORMAT);
        $this->store->save(1, 3, 7, 'c1', 'a.com');

        $record = $this->store->find(1);
        $this->assertNotNull($record);
        $this->assertSame([1, 3, 7, 'c1', 'a.com', false], [
            $record->serviceId,
            $record->serverId,
            $record->clientId,
            $record->customerId,
            $record->domainId,
            $record->isTerminated(),
        ]);

        $this->store->markTerminated(1);
        $terminated = $this->store->find(1);
        $this->assertNotNull($terminated);
        $terminatedAt = $terminated->terminatedAt;
        $this->assertNotNull($terminatedAt);
        $this->assertGreaterThanOrEqual($before, $terminatedAt);
        $this->assertLessThanOrEqual(gmdate(ServiceStore::TIME_FORMAT), $terminatedAt);

        $this->store->save(1, 3, 7, 'c2', 'a.com');
        $saved = $this->store->find(1);
        $this->assertNotNull($saved);
        $this->assertFalse($saved->isTerminated());
        $this->assertSame('c2', $saved->customerId);
        $this->assertCount(1, FakeDatabase::$tables[ServiceStore::TABLE]);
    }

    public function testTheClientsLiveServiceIsItsNewestOther(): void
    {
        $this->seed(
            [
                'service_id' => 1,
                'client_id' => 7,
                'customer_id' => 'old',
                'domain_id' => 'a.com',
                'created_at' => '2026-01-01 00:00:00',
            ]
        );
        $this->seed(
            [
                'service_id' => 2,
                'client_id' => 7,
                'customer_id' => 'new',
                'domain_id' => 'b.com',
                'created_at' => '2026-06-01 00:00:00',
            ]
        );
        $this->seed([
            'service_id' => 3, 'client_id' => 7, 'customer_id' => 'gone', 'domain_id' => 'c.com',
            'created_at' => '2026-07-01 00:00:00', 'terminated_at' => '2026-07-01 00:00:00',
        ]);
        $this->seed(
            [
                'service_id' => 4,
                'client_id' => 8,
                'customer_id' => 'other',
                'domain_id' => 'd.com',
                'created_at' => '2026-08-01 00:00:00',
            ]
        );

        $this->assertSame('new', $this->store->findLiveForClient(7, 1, 99)?->customerId);
        $this->assertSame('old', $this->store->findLiveForClient(7, 1, 2)?->customerId);
        $this->assertNull($this->store->findLiveForClient(9, 1, 99));
    }

    public function testTheClientsLiveServiceIsOnlyLookedForOnTheSameServer(): void
    {
        $this->seed([
            'service_id' => 1, 'server_id' => 1, 'client_id' => 7, 'customer_id' => 'a', 'domain_id' => 'a.com',
        ]);
        $this->seed([
            'service_id' => 2, 'server_id' => 2, 'client_id' => 7, 'customer_id' => 'b', 'domain_id' => 'b.com',
            'created_at' => '2026-12-01 00:00:00',
        ]);

        $this->assertSame('a', $this->store->findLiveForClient(7, 1, 99)?->customerId);
        $this->assertSame('b', $this->store->findLiveForClient(7, 2, 99)?->customerId);
        $this->assertNull($this->store->findLiveForClient(7, 3, 99));
        $this->assertSame(2, $this->store->findLiveForClientOnOtherServer(7, 1)?->serviceId);
        $this->assertSame(1, $this->store->findLiveForClientOnOtherServer(7, 2)?->serviceId);
        $this->assertNull($this->store->findLiveForClientOnOtherServer(8, 1));
    }

    public function testATerminatedServiceOnAnotherServerIsNotLive(): void
    {
        $this->seed([
            'service_id' => 1, 'server_id' => 2, 'client_id' => 7, 'customer_id' => 'a', 'domain_id' => 'a.com',
            'terminated_at' => '2026-11-20 10:00:00',
        ]);

        $this->assertNull($this->store->findLiveForClientOnOtherServer(7, 1));
    }

    public function testAServiceLeftAtServerZeroCountsAsOnAnotherServer(): void
    {
        // The migration leaves 0 on a service WHMCS no longer has: Create still asks whether its key owns the customer.
        $this->seed([
            'service_id' => 1, 'server_id' => 2, 'client_id' => 7, 'customer_id' => 'a', 'domain_id' => 'a.com',
        ]);
        $this->seed([
            'service_id' => 2, 'server_id' => 0, 'client_id' => 7, 'customer_id' => 'b', 'domain_id' => 'b.com',
            'created_at' => '2026-12-01 00:00:00',
        ]);

        $this->assertSame(2, $this->store->findLiveForClientOnOtherServer(7, 1)?->serviceId);
        $this->assertSame(0, $this->store->findLiveForClientOnOtherServer(7, 1)?->serverId);
        $this->assertSame(2, $this->store->findLiveForClientOnOtherServer(7, 2)?->serviceId);
        $this->assertSame(1, $this->store->findLiveForClientOnOtherServer(7, 0)?->serviceId);
    }

    /** A mod_cookiepal_services table as a build before server_id created it, with two rows. */
    private function seedTableWithoutServerId(): void
    {
        FakeDatabase::$tables[ServiceStore::TABLE] = [
            ['service_id' => 11, 'client_id' => 7, 'customer_id' => 'c', 'domain_id' => 'a.com',
                'created_at' => '2026-11-20 10:00:00', 'terminated_at' => null],
            ['service_id' => 12, 'client_id' => 7, 'customer_id' => 'c', 'domain_id' => 'b.com',
                'created_at' => '2026-11-20 10:00:00', 'terminated_at' => null],
        ];
        FakeDatabase::$columns[ServiceStore::TABLE] = array_map(
            fn (string $name): array => ['type' => 'x', 'name' => $name, 'arguments' => [], 'modifiers' => []],
            ['service_id', 'client_id', 'customer_id', 'domain_id', 'created_at', 'terminated_at']
        );
        // Service 11 is on server 4 in WHMCS; service 12 is gone from WHMCS.
        FakeDatabase::$tables['tblhosting'] = [['id' => 11, 'server' => 4]];
    }

    public function testAnOlderTableGainsServerIdFilledFromWhmcs(): void
    {
        $this->seedTableWithoutServerId();

        $this->assertSame(4, $this->store->find(11)?->serverId);
        $this->assertSame(0, $this->store->find(12)?->serverId);

        $this->assertSame(1, FakeDatabase::$alterCalls);
        $this->assertSame(0, FakeDatabase::$createCalls);
        $added = end(FakeDatabase::$columns[ServiceStore::TABLE]);
        $this->assertNotFalse($added);
        $this->assertSame(
            ['unsignedInteger', 'server_id', ['default']],
            [$added['type'], $added['name'], $added['modifiers']]
        );
        $this->assertSame(11, $this->store->findLiveForClient(7, 4, 99)?->serviceId);
    }

    public function testAColumnAnotherRequestAddedFirstIsUsed(): void
    {
        $this->seedTableWithoutServerId();
        FakeDatabase::$alterRace = true;

        $this->assertSame(4, $this->store->find(11)?->serverId);
        $this->assertSame(0, FakeDatabase::$alterCalls);
    }

    public function testANewTableIsNotAltered(): void
    {
        $this->store->find(1);
        ServiceStore::resetForTests();
        $this->store->find(1);

        $this->assertSame(0, FakeDatabase::$alterCalls);
    }

    public function testOtherLiveServicesOnACustomerOrWebsite(): void
    {
        $this->seed(['service_id' => 1, 'client_id' => 7, 'customer_id' => 'c', 'domain_id' => 'a.com']);
        $this->seed(
            [
                'service_id' => 2,
                'client_id' => 7,
                'customer_id' => 'c',
                'domain_id' => 'b.com',
                'terminated_at' => '2026-11-21 09:00:00',
            ]
        );

        $this->assertSame(1, $this->store->findOtherLiveOnCustomer('c', 2)?->serviceId);
        $this->assertNull($this->store->findOtherLiveOnCustomer('c', 1));
        $this->assertSame(1, $this->store->findOtherLiveForWebsite('c', 'a.com', 3)?->serviceId);
        $this->assertNull($this->store->findOtherLiveForWebsite('c', 'a.com', 1));
        $this->assertNull($this->store->findOtherLiveForWebsite('c', 'b.com', 3));
    }

    public function testTheClientsRecentTerminations(): void
    {
        $this->seed(
            [
                'service_id' => 1,
                'client_id' => 7,
                'customer_id' => 'c',
                'domain_id' => 'a.com',
                'terminated_at' => '2026-11-20 08:00:00',
            ]
        );
        $this->seed(
            [
                'service_id' => 2,
                'client_id' => 7,
                'customer_id' => 'c',
                'domain_id' => 'b.com',
                'terminated_at' => '2026-11-24 08:00:00',
            ]
        );
        $this->seed(['service_id' => 3, 'client_id' => 7, 'customer_id' => 'c', 'domain_id' => 'c.com']);

        $this->assertSame(2, $this->store->findTerminatedForClientSince(7, '2026-11-23 00:00:00')?->serviceId);
        $this->assertSame(1, $this->store->findTerminatedForClientSince(7, '2026-11-19 00:00:00', 'a.com')?->serviceId);
        $this->assertNull($this->store->findTerminatedForClientSince(7, '2026-11-23 00:00:00', 'a.com'));
        $this->assertNull($this->store->findTerminatedForClientSince(8, '2026-11-01 00:00:00'));
    }
}
