<?php

declare(strict_types=1);

namespace CookiePal\Tests\Support;

use PHPUnit\Framework\TestCase;
use WHMCS\Config\Setting;
use WHMCS\Module\Server\CookiePal\Module;
use WHMCS\Module\Server\CookiePal\ServiceStore;

/**
 * Tests that call the module's cookiepal_<Function>s, with a fresh database, transport and logs.
 * The transport fails the test on any request it wasn't told to expect.
 */
abstract class ModuleTestCase extends TestCase
{
    use ApiFixtures;

    protected FakeTransport $transport;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../modules/servers/cookiepal/cookiepal.php';
    }

    protected function setUp(): void
    {
        FakeDatabase::reset();
        ServiceStore::resetForTests();
        ModuleLog::reset();
        Setting::$values = [];
        unset($_SESSION['Language']);
        $this->transport = new FakeTransport();
        Module::useTransport($this->transport);
    }

    protected function tearDown(): void
    {
        Module::useTransport(null);
        $this->assertSame([], $this->transport->mismatches, 'Unexpected API requests');
        $this->assertSame(0, $this->transport->pending(), 'Expected API requests were not sent');
    }

    /**
     * WHMCS's $params for service 101 of client 7, on a growth product.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function params(array $overrides = []): array
    {
        return array_replace_recursive([
            'serviceid' => 101,
            'userid' => 7,
            'pid' => 3,
            'domain' => 'https://www.Example.com/shop?x=1',
            'serverhostname' => self::HOST,
            'serverpassword' => self::API_KEY,
            'serversecure' => true,
            'configoption1' => 'growth',
            'configoptions' => [],
            'clientsdetails' => [
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'companyname' => '',
                'email' => 'ada@example.com',
                'language' => 'english',
            ],
        ], $overrides);
    }

    /** A mod_cookiepal_services row, written after the module's own table creation. */
    protected function seedService(
        int $serviceId = 101,
        int $clientId = 7,
        string $customerId = self::CUSTOMER,
        string $domainId = 'example.com',
        ?string $terminatedAt = null,
        string $createdAt = '2026-11-20 10:00:00'
    ): void {
        (new ServiceStore())->find(0);
        FakeDatabase::seed(ServiceStore::TABLE, [
            'service_id' => $serviceId,
            'client_id' => $clientId,
            'customer_id' => $customerId,
            'domain_id' => $domainId,
            'created_at' => $createdAt,
            'terminated_at' => $terminatedAt,
        ]);
    }

    /** The service's terminated_at; the row must exist. */
    protected function terminatedAt(int $serviceId = 101): ?string
    {
        $row = $this->row($serviceId);
        $this->assertNotNull($row, "Service {$serviceId} has no row");
        $terminatedAt = $row['terminated_at'];
        $this->assertTrue($terminatedAt === null || is_string($terminatedAt));

        return $terminatedAt;
    }

    /** @return array<string, mixed>|null */
    protected function row(int $serviceId = 101): ?array
    {
        foreach (FakeDatabase::$tables[ServiceStore::TABLE] ?? [] as $row) {
            if ((int) $row['service_id'] === $serviceId) {
                return $row;
            }
        }

        return null;
    }
}
