<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * One row of mod_cookiepal_services: which CookiePal customer and website a WHMCS service is.
 */
final class ServiceRecord
{
    public function __construct(
        public readonly int $serviceId,
        public readonly int $clientId,
        public readonly string $customerId,
        public readonly string $domainId,
        public readonly ?string $terminatedAt
    ) {
    }

    public static function fromRow(\stdClass $row): self
    {
        $terminatedAt = $row->terminated_at ?? null;

        return new self(
            (int) $row->service_id,
            (int) $row->client_id,
            (string) $row->customer_id,
            (string) $row->domain_id,
            is_string($terminatedAt) && $terminatedAt !== '' ? $terminatedAt : null
        );
    }

    public function isTerminated(): bool
    {
        return $this->terminatedAt !== null;
    }
}
