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
            $row + ['created_at' => '2026-11-20 10:00:00', 'terminated_at' => null]
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
        $this->store->save(1, 7, 'c1', 'a.com');

        $record = $this->store->find(1);
        $this->assertNotNull($record);
        $this->assertSame([1, 7, 'c1', 'a.com', false], [
            $record->serviceId, $record->clientId, $record->customerId, $record->domainId, $record->isTerminated(),
        ]);

        $this->store->markTerminated(1);
        $terminated = $this->store->find(1);
        $this->assertNotNull($terminated);
        $terminatedAt = $terminated->terminatedAt;
        $this->assertNotNull($terminatedAt);
        $this->assertGreaterThanOrEqual($before, $terminatedAt);
        $this->assertLessThanOrEqual(gmdate(ServiceStore::TIME_FORMAT), $terminatedAt);

        $this->store->save(1, 7, 'c2', 'a.com');
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

        $this->assertSame('new', $this->store->findLiveForClient(7, 99)?->customerId);
        $this->assertSame('old', $this->store->findLiveForClient(7, 2)?->customerId);
        $this->assertNull($this->store->findLiveForClient(9, 99));
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
