<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * The mod_cookiepal_services table, through WHMCS's Capsule. Created on first use.
 *
 * service_id is the WHMCS service (tblhosting.id), and server_id the WHMCS server (tblservers.id) it was
 * created on, which holds the agency API key that owns its customer. customer_id and domain_id are what the
 * CookiePal API returned on create. Times are UTC. terminated_at is set once TerminateAccount
 * succeeds; the row is kept so the admin can still see which CookiePal website the service was.
 */
final class ServiceStore
{
    public const TABLE = 'mod_cookiepal_services';
    public const TIME_FORMAT = 'Y-m-d H:i:s';

    private static bool $tableReady = false;

    /** @internal Tests only: forget that the table was checked. */
    public static function resetForTests(): void
    {
        self::$tableReady = false;
    }

    public function find(int $serviceId): ?ServiceRecord
    {
        $this->ensureTable();

        return $this->record(Capsule::table(self::TABLE)->where('service_id', $serviceId)->first());
    }

    /**
     * The client's newest service on this CookiePal server that isn't terminated, other than $exceptServiceId.
     * Only those share a customer: another server can hold another agency's key.
     */
    public function findLiveForClient(int $clientId, int $serverId, int $exceptServiceId): ?ServiceRecord
    {
        $this->ensureTable();

        return $this->record(Capsule::table(self::TABLE)
            ->where('client_id', $clientId)
            ->where('server_id', $serverId)
            ->where('service_id', '!=', $exceptServiceId)
            ->whereNull('terminated_at')
            ->orderBy('created_at', 'desc')
            ->first());
    }

    /** The client's newest live service on any CookiePal server other than $serverId. */
    public function findLiveForClientOnOtherServer(int $clientId, int $serverId): ?ServiceRecord
    {
        $this->ensureTable();

        return $this->record(Capsule::table(self::TABLE)
            ->where('client_id', $clientId)
            ->where('server_id', '!=', $serverId)
            ->whereNull('terminated_at')
            ->orderBy('created_at', 'desc')
            ->first());
    }

    /** Another live service on this CookiePal customer, other than $exceptServiceId. */
    public function findOtherLiveOnCustomer(string $customerId, int $exceptServiceId): ?ServiceRecord
    {
        $this->ensureTable();

        return $this->record(Capsule::table(self::TABLE)
            ->where('customer_id', $customerId)
            ->where('service_id', '!=', $exceptServiceId)
            ->whereNull('terminated_at')
            ->first());
    }

    /** Another live service for this exact CookiePal website, other than $exceptServiceId. */
    public function findOtherLiveForWebsite(string $customerId, string $domainId, int $exceptServiceId): ?ServiceRecord
    {
        $this->ensureTable();

        return $this->record(Capsule::table(self::TABLE)
            ->where('customer_id', $customerId)
            ->where('domain_id', $domainId)
            ->where('service_id', '!=', $exceptServiceId)
            ->whereNull('terminated_at')
            ->first());
    }

    /**
     * The client's most recently terminated service since $sinceUtc, for this website when one is given.
     */
    public function findTerminatedForClientSince(
        int $clientId,
        string $sinceUtc,
        ?string $domainId = null
    ): ?ServiceRecord {
        $this->ensureTable();
        $query = Capsule::table(self::TABLE)
            ->where('client_id', $clientId)
            ->where('terminated_at', '>=', $sinceUtc);
        if ($domainId !== null) {
            $query->where('domain_id', $domainId);
        }

        return $this->record($query->orderBy('terminated_at', 'desc')->first());
    }

    /** Inserts the service's row, or replaces the one it has (live or terminated). */
    public function save(int $serviceId, int $serverId, int $clientId, string $customerId, string $domainId): void
    {
        $this->ensureTable();
        Capsule::table(self::TABLE)->updateOrInsert(
            ['service_id' => $serviceId],
            [
                'server_id' => $serverId,
                'client_id' => $clientId,
                'customer_id' => $customerId,
                'domain_id' => $domainId,
                'created_at' => self::now(),
                'terminated_at' => null,
            ]
        );
    }

    public function markTerminated(int $serviceId): void
    {
        $this->ensureTable();
        Capsule::table(self::TABLE)
            ->where('service_id', $serviceId)
            ->update(['terminated_at' => self::now()]);
    }

    /** The current time in the table's format, in UTC. */
    public static function now(int $offsetSeconds = 0): string
    {
        return gmdate(self::TIME_FORMAT, time() + $offsetSeconds);
    }

    private function record(?object $row): ?ServiceRecord
    {
        return $row instanceof \stdClass ? ServiceRecord::fromRow($row) : null;
    }

    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }

        $schema = Capsule::schema();
        if (!$schema->hasTable(self::TABLE)) {
            try {
                $schema->create(self::TABLE, function ($table): void {
                    $table->unsignedInteger('service_id')->primary();
                    $table->unsignedInteger('server_id');
                    $table->unsignedInteger('client_id')->index();
                    $table->string('customer_id', 64)->index();
                    $table->string('domain_id', 255);
                    $table->dateTime('created_at');
                    $table->dateTime('terminated_at')->nullable();
                });
            } catch (\Throwable $e) {
                // Two first requests at once: the other one created it.
                if (!Capsule::schema()->hasTable(self::TABLE)) {
                    throw $e;
                }
            }
        } elseif (!$schema->hasColumn(self::TABLE, 'server_id')) {
            self::addServerId();
        }
        self::$tableReady = true;
    }

    /**
     * A table from a build before server_id: adds the column, and fills it with each service's server as WHMCS
     * has it now (tblhosting.server). A service WHMCS no longer has keeps 0, which no live lookup matches.
     */
    private static function addServerId(): void
    {
        try {
            Capsule::schema()->table(self::TABLE, function ($table): void {
                $table->unsignedInteger('server_id')->default(0);
            });
        } catch (\Throwable $e) {
            // Two requests at once: the other one added it.
            if (!Capsule::schema()->hasColumn(self::TABLE, 'server_id')) {
                throw $e;
            }
        }
        foreach (Capsule::table(self::TABLE)->get() as $row) {
            $server = Capsule::table('tblhosting')->where('id', $row->service_id)->value('server');
            Capsule::table(self::TABLE)
                ->where('service_id', $row->service_id)
                ->update(['server_id' => is_numeric($server) ? (int) $server : 0]);
        }
    }
}
