<?php

declare(strict_types=1);

namespace CookiePal\Tests\Support;

/** Answers shaped like the agency API's, and the ids they use. */
trait ApiFixtures
{
    /** Not a real key: the right length, and obviously fake. */
    public const API_KEY = 'cpk_test_00000000000000000000000000000000000000';
    public const HOST = 'dev-agency-api.cookiepal.io';
    public const CUSTOMER = '3f1c2a9e-7b4d-4c1e-9a8f-0d2b6e5c4a11';
    public const OTHER_CUSTOMER = '8d2e4f6a-1b3c-4d5e-8f70-9a1b2c3d4e5f';
    public const HANDLER = 'https://cdn.cookiepal.io/client_data/9b7e6d5c-4a3b-4c2d-8e1f-0a9b8c7d6e5f/script.js';

    /**
     * One domain item of GET /external/customers/{c}, with the plan and status fields.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function domainItem(string $domainId = 'example.com', array $overrides = []): array
    {
        return array_replace([
            'domainId' => $domainId,
            'handler' => self::HANDLER,
            'bannerSettings' => ['languages' => ['defaultLanguage' => 'en-GB']],
            'plan' => 'growth',
            'suspended' => false,
            'verification' => 'not_verified',
            'status' => 'active',
        ], $overrides);
    }

    /**
     * GET /external/customers/{c}
     *
     * @param list<array<string, mixed>> $domains
     *
     * @return array<string, mixed>
     */
    protected function customerBody(array $domains, string $customerId = self::CUSTOMER): array
    {
        return ['customerId' => $customerId, 'customerName' => 'Ada Lovelace', 'domains' => $domains];
    }

    /**
     * 201 of POST /external/customer
     *
     * @return array<string, mixed>
     */
    protected function createdCustomerBody(string $domainId = 'example.com', string $plan = 'growth'): array
    {
        return [
            'customerId' => self::CUSTOMER,
            'customerName' => 'Ada Lovelace',
            'customerEmail' => 'ada@example.com',
            'domain' => ['domainId' => $domainId, 'bannerSettings' => [], 'handler' => self::HANDLER, 'plan' => $plan],
        ];
    }

    /**
     * 201 of POST /external/domain
     *
     * @return array<string, mixed>
     */
    protected function addedDomainBody(
        string $domainId,
        string $customerId = self::CUSTOMER,
        string $plan = 'growth'
    ): array {
        return [
            'customerId' => $customerId,
            'domain' => ['domainId' => $domainId, 'bannerSettings' => [], 'handler' => self::HANDLER, 'plan' => $plan],
        ];
    }

    protected function customerPath(string $customerId = self::CUSTOMER): string
    {
        return '/external/customers/' . $customerId;
    }

    protected function domainPath(string $suffix = '', string $domainId = 'example.com'): string
    {
        return '/external/customers/' . self::CUSTOMER . '/domains/' . $domainId . $suffix;
    }
}
