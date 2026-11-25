<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use CookiePal\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WHMCS\Config\Setting;
use WHMCS\Module\Server\CookiePal\IdempotencyKey;
use WHMCS\Module\Server\CookiePal\ServiceRecord;

/** The key Create sends (the System URL is https://billing.example.net/ in every test). */
final class IdempotencyKeyTest extends ModuleTestCase
{
    /** What the agency API accepts. */
    private const ALLOWED = '/^[A-Za-z0-9._:-]{1,128}\z/';

    private static function terminated(int $serviceId, string $at): ServiceRecord
    {
        return ServiceRecord::fromRow((object) [
            'service_id' => $serviceId,
            'client_id' => 7,
            'customer_id' => self::CUSTOMER,
            'domain_id' => 'example.com',
            'terminated_at' => $at,
        ]);
    }

    public function testTheKeyIsTheInstallAndTheService(): void
    {
        $this->assertSame('whmcs-9671d0febcefbe9b-101', IdempotencyKey::forCreate(101, null));
    }

    public function testTheSameServiceAlwaysGetsTheSameKey(): void
    {
        $this->assertSame(IdempotencyKey::forCreate(101, null), IdempotencyKey::forCreate(101, null));
    }

    public function testTwoServicesGetDifferentKeys(): void
    {
        $this->assertNotSame(IdempotencyKey::forCreate(101, null), IdempotencyKey::forCreate(102, null));
    }

    public function testTheSameServiceOnTwoInstallsGetsDifferentKeys(): void
    {
        $here = IdempotencyKey::forCreate(101, null);
        Setting::$values['SystemURL'] = 'https://whmcs.other-host.example/';

        $this->assertSame('whmcs-aed6563acf133d42-101', IdempotencyKey::forCreate(101, null));
        $this->assertNotSame($here, IdempotencyKey::forCreate(101, null));
    }

    /** @return array<string, array{string}> */
    public static function sameInstall(): array
    {
        return [
            'http' => ['http://billing.example.net'],
            'upper case' => ['HTTPS://Billing.Example.NET/'],
            'spaces' => ['  https://billing.example.net/  '],
        ];
    }

    #[DataProvider('sameInstall')]
    public function testTheSchemeCaseAndFinalSlashOfTheSystemUrlDoNotMatter(string $url): void
    {
        Setting::$values['SystemURL'] = $url;

        $this->assertSame('whmcs-9671d0febcefbe9b-101', IdempotencyKey::forCreate(101, null));
    }

    /** @return array<string, array{array<string, string>}> */
    public static function noSystemUrl(): array
    {
        return ['missing' => [[]], 'empty' => [['SystemURL' => '']], 'only a scheme' => [['SystemURL' => 'https://']]];
    }

    /** @param array<string, string> $settings */
    #[DataProvider('noSystemUrl')]
    public function testWithoutASystemUrlThereIsNoKey(array $settings): void
    {
        Setting::$values = $settings;

        $this->assertNull(IdempotencyKey::forCreate(101, null));
    }

    public function testAServiceCreatedAgainAfterTerminateGetsANewKey(): void
    {
        $this->assertSame(
            'whmcs-9671d0febcefbe9b-101-20261120093000',
            IdempotencyKey::forCreate(101, self::terminated(101, '2026-11-20 09:30:00'))
        );
        $this->assertNotSame(
            IdempotencyKey::forCreate(101, self::terminated(101, '2026-11-20 09:30:00')),
            IdempotencyKey::forCreate(101, self::terminated(101, '2026-11-24 15:00:00'))
        );
    }

    public function testTheLongestKeyIsWithinWhatTheApiAccepts(): void
    {
        $key = IdempotencyKey::forCreate(PHP_INT_MAX, self::terminated(PHP_INT_MAX, '2026-11-20 09:30:00'));

        $this->assertIsString($key);
        $this->assertMatchesRegularExpression(self::ALLOWED, $key);
    }
}
