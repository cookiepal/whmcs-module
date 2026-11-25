<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * What each cookiepal_<Function> in cookiepal.php does. Those functions only call in here,
 * so that WHMCS sees no helper function it could mistake for a module command.
 *
 * Errors come back the way WHMCS expects: a string other than 'success' for the account
 * actions, `['success' => false, ...]` for TestConnection and single sign-on.
 */
final class Module
{
    public const VERSION = '1.0.0';
    public const SUPPORT_EMAIL = 'support@cookiepal.io';

    /** CookiePal deletes a removed customer or website a day later; this allows for the deletion running late. */
    private const REMOVAL_WINDOW_SECONDS = 2 * 86400;

    private const PLAN_CONFLICTS = [
        'SCHEDULED_FOR_DELETION' => 'This CookiePal website is scheduled for deletion, so its plan can\'t change.',
        'PLAN_NOT_MANAGED_BY_AGENCY' => 'The customer pays for this website\'s plan in CookiePal themselves, '
            . 'so WHMCS can\'t change it.',
        'FREE_PLAN_OVER_LIMIT' => 'This website has more traffic than the free plan allows. Choose a paid plan.',
        'PLAN_CHANGE_CONFLICT' => 'Another plan change for this website was in progress. Try again in a minute.',
    ];

    private static ?Transport $transport = null;

    /** @internal Tests only: the transport every API client uses. Null restores curl. */
    public static function useTransport(?Transport $transport): void
    {
        self::$transport = $transport;
    }

    /** @param array<string, mixed> $params */
    public static function createAccount(array $params): string
    {
        return self::run('CreateAccount', $params, function () use ($params): string {
            $serviceId = self::serviceId($params);
            $clientId = self::intParam($params, 'userid');
            $store = new ServiceStore();

            // WHMCS admins retry. A service that already has its website is done.
            $existing = $store->find($serviceId);
            if ($existing !== null && !$existing->isTerminated()) {
                return 'success';
            }

            $domain = Hostname::website(self::stringParam($params, 'domain'));
            $plan = Plan::fromParams($params);
            $api = self::api($params, 'CreateAccount');
            // Run again after a lost answer, either create answers 200 with its first body: saved like a 201.
            $key = IdempotencyKey::forCreate($serviceId, $existing);

            // A second order from the same client, on the same CookiePal server, adds a website to its customer.
            $serverId = self::intParam($params, 'serverid');
            $live = $clientId > 0 ? $store->findLiveForClient($clientId, $serverId, $serviceId) : null;
            if ($live !== null) {
                $domainId = self::addDomainToCustomer(
                    $api,
                    $store,
                    $live->customerId,
                    $domain,
                    $plan,
                    $serviceId,
                    $clientId,
                    $key
                );
                if ($domainId !== null) {
                    self::saveCreated($store, $serviceId, $serverId, $clientId, $live->customerId, $domainId);

                    return 'success';
                }
                // That customer is gone from CookiePal: start a new one below.
            }

            [$customerId, $domainId] = self::createCustomer(
                $api,
                $store,
                $params,
                $serverId,
                $clientId,
                $domain,
                $plan,
                $key
            );
            self::saveCreated($store, $serviceId, $serverId, $clientId, $customerId, $domainId);

            return 'success';
        });
    }

    /** @param array<string, mixed> $params */
    public static function suspendAccount(array $params): string
    {
        return self::run('SuspendAccount', $params, function () use ($params): string {
            $record = self::liveRecord($params);
            try {
                self::api($params, 'SuspendAccount')->suspend($record->customerId, $record->domainId);
            } catch (ApiException $e) {
                if ($e->is(409, 'SCHEDULED_FOR_DELETION')) {
                    throw new ModuleException(
                        'This CookiePal website is scheduled for deletion, so it can\'t be suspended.'
                    );
                }
                throw $e;
            }

            return 'success';
        });
    }

    /** @param array<string, mixed> $params */
    public static function unsuspendAccount(array $params): string
    {
        return self::run('UnsuspendAccount', $params, function () use ($params): string {
            $record = self::liveRecord($params);
            self::api($params, 'UnsuspendAccount')->unsuspend($record->customerId, $record->domainId);

            return 'success';
        });
    }

    /** @param array<string, mixed> $params */
    public static function changePackage(array $params): string
    {
        return self::run('ChangePackage', $params, function () use ($params): string {
            $record = self::liveRecord($params);
            $plan = Plan::fromParams($params);
            self::setPlan(self::api($params, 'ChangePackage'), $record->customerId, $record->domainId, $plan);

            return 'success';
        });
    }

    /**
     * Removes the service's website, or its whole CookiePal customer when nothing else uses it.
     * The customer is never deleted while another live service of this WHMCS points at it.
     *
     * @param array<string, mixed> $params
     */
    public static function terminateAccount(array $params): string
    {
        return self::run('TerminateAccount', $params, function () use ($params): string {
            $serviceId = self::serviceId($params);
            $store = new ServiceStore();
            $record = $store->find($serviceId);
            if ($record === null || $record->isTerminated()) {
                return 'success';
            }

            $api = self::api($params, 'TerminateAccount');
            try {
                $customer = $api->getCustomer($record->customerId);
            } catch (ApiException $e) {
                if (!$e->is(404)) {
                    throw $e;
                }
                $store->markTerminated($serviceId);

                return 'success';
            }

            $own = self::findDomain($customer, $record->domainId);
            // Other websites that keep the customer: active ones, not inactive or scheduled ones.
            $otherActive = array_filter(
                self::domainsOf($customer),
                fn (array $d): bool => $d !== $own && self::isUsable($d)
            );
            $sharedWith = $store->findOtherLiveOnCustomer($record->customerId, $serviceId);

            if ($sharedWith === null && $otherActive === []) {
                self::ignoreNotFound(fn () => $api->deleteCustomer($record->customerId));
            } elseif ($own !== null && !self::isBeingRemoved($own)) {
                try {
                    self::ignoreNotFound(fn () => $api->deleteDomain($record->customerId, $record->domainId));
                } catch (ApiException $e) {
                    if (!self::isUsable($own) && $e->is(400)) {
                        // An inactive website: the API's "only active domain" check can still refuse it when exactly
                        // one other website is active. The service ends anyway; the website was already inactive.
                        Logger::activity(
                            'service #' . $serviceId . ' is terminated, but CookiePal didn\'t delete its inactive '
                            . 'website ' . $record->domainId . ' on customer ' . $record->customerId . ': '
                            . $e->adminMessage()
                        );
                        $store->markTerminated($serviceId);

                        return 'success';
                    }
                    if ($sharedWith === null || !$e->is(400, 'ONLY_ACTIVE_DOMAIN')) {
                        throw $e;
                    }
                    // The other service's website isn't active in CookiePal, so this is the customer's
                    // only active one. Terminating that service first lets this one remove the customer.
                    throw new ModuleException(
                        'CookiePal won\'t remove ' . $record->domainId . ' because it\'s the customer\'s only '
                        . 'active website, and service #' . $sharedWith->serviceId . ' still uses that customer. '
                        . 'Terminate service #' . $sharedWith->serviceId . ' first, then run Terminate again, '
                        . 'or contact CookiePal support (' . self::SUPPORT_EMAIL . ').'
                    );
                }
            }

            $store->markTerminated($serviceId);

            return 'success';
        });
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{success: bool, error: string}
     */
    public static function testConnection(array $params): array
    {
        try {
            self::api($params, 'TestConnection')->listCustomers();

            return ['success' => true, 'error' => ''];
        } catch (ApiException $e) {
            return ['success' => false, 'error' => $e->adminMessage()];
        } catch (\Throwable $e) {
            Logger::unexpected('TestConnection', $params, $e);

            return ['success' => false, 'error' => 'CookiePal module error: ' . $e->getMessage()];
        }
    }

    /**
     * The client sees only client-safe text from the language file; the admin area gets the detail,
     * which is also in the Module Log.
     *
     * @param array<string, mixed> $params
     *
     * @return array{success: bool, redirectTo?: string, errorMsg?: string}
     */
    public static function serviceSingleSignOn(array $params): array
    {
        $admin = defined('ADMINAREA');
        try {
            $record = self::liveRecord($params);
            $result = self::api($params, 'ServiceSingleSignOn')->createSsoLink($record->customerId, $record->domainId);
            $url = $result['url'] ?? null;
            if (!is_string($url) || preg_match('#^https://#i', $url) !== 1) {
                throw new ApiException(200, 'CookiePal returned no sign-in link.', ApiException::BAD_RESPONSE);
            }

            return ['success' => true, 'redirectTo' => $url];
        } catch (ApiException $e) {
            if ($e->is(409, 'SSO_USER_NOT_ELIGIBLE')) {
                return ['success' => false, 'errorMsg' => $admin
                    ? 'The customer\'s CookiePal user can\'t sign in with a link, for example because their '
                        . 'account needs two-factor authentication. They can sign in at app.cookiepal.io with the '
                        . 'email the invite went to.'
                    : Lang::forParams($params)['sso_sign_in']];
            }
            $detail = $e->is(404, 'USER_NOT_FOUND')
                ? 'This CookiePal customer has no user to sign in as. Contact CookiePal support ('
                    . self::SUPPORT_EMAIL . ').'
                : $e->adminMessage();
        } catch (ModuleException $e) {
            $detail = $e->getMessage();
        } catch (\Throwable $e) {
            Logger::unexpected('ServiceSingleSignOn', $params, $e);
            $detail = 'CookiePal module error: ' . $e->getMessage();
        }

        return ['success' => false, 'errorMsg' => $admin ? $detail : Lang::forParams($params)['sso_unavailable']];
    }

    /**
     * The overview's module output: the install code, the plan, the banner status and the SSO button.
     *
     * @param array<string, mixed> $params
     *
     * @return array{tabOverviewModuleOutputTemplate: string, templateVariables: array<string, mixed>}
     */
    public static function clientArea(array $params): array
    {
        $serviceId = self::intParam($params, 'serviceid');
        $lang = Lang::forParams($params);
        $view = [
            'state' => 'pending',
            'domain' => '',
            'snippet' => '',
            'planLabel' => '',
            'installed' => false,
            'suspended' => false,
            'scheduledDeletion' => false,
        ];

        try {
            $record = $serviceId > 0 ? (new ServiceStore())->find($serviceId) : null;
            if ($record !== null && $record->isTerminated()) {
                $view['state'] = 'terminated';
            } elseif ($record !== null) {
                $view['domain'] = $record->domainId;
                $customer = self::api($params, 'ClientArea', true)->getCustomer($record->customerId);
                $domain = self::findDomain($customer, $record->domainId);
                $view['state'] = 'missing';
                if ($domain !== null) {
                    $facts = self::websiteFacts($domain);
                    $view = [
                        'state' => 'ready',
                        'domain' => $record->domainId,
                        'snippet' => Snippet::forHandler($facts['handler']),
                        'planLabel' => $facts['plan'] === Plan::OTHER
                            ? $lang['plan_other']
                            : Plan::LABELS[$facts['plan']],
                        'installed' => $facts['installed'],
                        'suspended' => $facts['suspended'],
                        'scheduledDeletion' => $facts['scheduledDeletion'] !== null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            if (!$e instanceof ApiException) {
                Logger::unexpected('ClientArea', $params, $e);
            }
            // A 404: the customer is gone from CookiePal, which waiting won't change.
            $view['state'] = $e instanceof ApiException && $e->is(404) ? 'missing' : 'error';
        }

        return [
            'tabOverviewModuleOutputTemplate' => 'templates/clientarea.tpl',
            'templateVariables' => [
                'cookiepal' => $view,
                'cookiepalLang' => $lang,
                'serviceid' => $serviceId,
            ],
        ];
    }

    /**
     * The fields on the service's page in the admin area. Values are HTML, so all are escaped.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, string>
     */
    public static function adminServicesTabFields(array $params): array
    {
        $serviceId = self::intParam($params, 'serviceid');
        try {
            $record = $serviceId > 0 ? (new ServiceStore())->find($serviceId) : null;
        } catch (\Throwable $e) {
            Logger::unexpected('AdminServicesTabFields', $params, $e);

            return ['CookiePal' => self::h('Couldn\'t read the module\'s table: ' . $e->getMessage())];
        }
        if ($record === null) {
            return ['CookiePal' => 'No CookiePal website yet. Run Create to set one up.'];
        }

        $fields = [
            'CookiePal customer ID' => self::h($record->customerId),
            'CookiePal website' => self::h($record->domainId),
        ];
        if ($record->isTerminated()) {
            return $fields + ['CookiePal status' => self::h('Terminated on ' . $record->terminatedAt . ' UTC')];
        }

        try {
            $customer = self::api($params, 'AdminServicesTabFields', true)->getCustomer($record->customerId);
        } catch (ApiException $e) {
            return $fields + ['CookiePal status' => self::h('Couldn\'t load: ' . $e->adminMessage())];
        } catch (\Throwable $e) {
            Logger::unexpected('AdminServicesTabFields', $params, $e);

            return $fields + [
                'CookiePal status' => self::h('Couldn\'t load: CookiePal module error: ' . $e->getMessage()),
            ];
        }
        $domain = self::findDomain($customer, $record->domainId);
        if ($domain === null) {
            return $fields + ['CookiePal status' => 'This website is no longer on the CookiePal customer.'];
        }

        $facts = self::websiteFacts($domain);
        $status = $facts['active'] ? 'Active' : 'Inactive';
        if ($facts['scheduledDeletion'] !== null) {
            $status .= ', scheduled for deletion on ' . $facts['scheduledDeletion'];
        }
        $handler = $facts['handler'];

        return $fields + [
            'Plan' => $facts['plan'] === Plan::OTHER ? 'Other (set in CookiePal)' : Plan::LABELS[$facts['plan']],
            'Banner installed' => self::h(($facts['installed'] ? 'Yes' : 'No') . ' (' . $facts['verification'] . ')'),
            'Script URL' => Snippet::isSafeHandler($handler)
                ? '<a href="' . self::h($handler) . '" target="_blank" rel="noopener">' . self::h($handler) . '</a>'
                : self::h($handler),
            'Suspended' => $facts['suspended'] ? 'Yes' : 'No',
            'CookiePal status' => self::h($status),
        ];
    }

    /** @return array<string, string> */
    public static function adminCustomButtonArray(): array
    {
        return [
            'Start scan' => 'StartScan',
            'Refresh status' => 'RefreshStatus',
        ];
    }

    /** @param array<string, mixed> $params */
    public static function startScan(array $params): string
    {
        return self::run('StartScan', $params, function () use ($params): string {
            $record = self::liveRecord($params);
            self::api($params, 'StartScan')->startScan($record->customerId, $record->domainId);

            return 'success';
        });
    }

    /**
     * Reads the website from CookiePal again. The admin tab shows the fresh status on reload.
     *
     * @param array<string, mixed> $params
     */
    public static function refreshStatus(array $params): string
    {
        return self::run('RefreshStatus', $params, function () use ($params): string {
            $record = self::liveRecord($params);
            $customer = self::api($params, 'RefreshStatus')->getCustomer($record->customerId);
            if (self::findDomain($customer, $record->domainId) === null) {
                throw new ModuleException(
                    'The website ' . $record->domainId . ' is no longer on this CookiePal customer.'
                );
            }

            return 'success';
        });
    }

    /**
     * Runs one account action and turns every failure into the string WHMCS shows the admin.
     *
     * @param array<string, mixed> $params
     * @param callable(): string $body
     */
    private static function run(string $action, array $params, callable $body): string
    {
        try {
            return $body();
        } catch (ModuleException $e) {
            return $e->getMessage();
        } catch (ApiException $e) {
            return $e->adminMessage();
        } catch (\Throwable $e) {
            Logger::unexpected($action, $params, $e);

            return 'CookiePal module error: ' . $e->getMessage();
        }
    }

    /**
     * The API client for this call's server record.
     *
     * @param array<string, mixed> $params
     */
    private static function api(array $params, string $action, bool $forPage = false): ApiClient
    {
        return ApiClient::forServer(
            self::stringParam($params, 'serverhostname'),
            self::stringParam($params, 'serverpassword'),
            self::$transport ?? new CurlTransport(),
            $action,
            'CookiePal-WHMCS/' . self::VERSION,
            $forPage
        );
    }

    /** @param array<string, mixed> $params */
    private static function serviceId(array $params): int
    {
        $serviceId = self::intParam($params, 'serviceid');
        if ($serviceId <= 0) {
            throw new ModuleException('WHMCS passed no service ID.');
        }

        return $serviceId;
    }

    /** @param array<string, mixed> $params */
    private static function intParam(array $params, string $name): int
    {
        return is_numeric($params[$name] ?? null) ? (int) $params[$name] : 0;
    }

    /** @param array<string, mixed> $params */
    private static function stringParam(array $params, string $name): string
    {
        return is_scalar($params[$name] ?? null) ? (string) $params[$name] : '';
    }

    /** @param array<string, mixed> $params */
    private static function liveRecord(array $params): ServiceRecord
    {
        $record = (new ServiceStore())->find(self::serviceId($params));
        if ($record === null) {
            throw new ModuleException('This service has no CookiePal website yet. Run Create first.');
        }
        if ($record->isTerminated()) {
            throw new ModuleException(
                'This service\'s CookiePal website was terminated on ' . $record->terminatedAt . ' UTC.'
            );
        }

        return $record;
    }

    /**
     * POST /external/domain for a customer the client already has.
     *
     * @return string|null the domainId, or null when the customer no longer exists
     */
    private static function addDomainToCustomer(
        ApiClient $api,
        ServiceStore $store,
        string $customerId,
        string $domain,
        string $plan,
        int $serviceId,
        int $clientId,
        ?string $idempotencyKey
    ): ?string {
        try {
            return self::domainIdFrom($api->addDomain($customerId, $domain, $plan, $idempotencyKey));
        } catch (ApiException $e) {
            if ($e->is(409, 'DOMAIN_EXISTS')) {
                return self::confirmExistingDomain($api, $store, $customerId, $domain, $plan, $serviceId, $e);
            }
            if ($e->is(409, 'DOMAIN_REGISTERED_ELSEWHERE')) {
                throw new ModuleException(self::elsewhereMessage($store, $clientId, $domain));
            }
            if ($e->is(404)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * The domain is already on this customer: an earlier Create whose answer was lost, or a second
     * order for the same website. Only the first is completed, and on the product's plan.
     */
    private static function confirmExistingDomain(
        ApiClient $api,
        ServiceStore $store,
        string $customerId,
        string $domain,
        string $plan,
        int $serviceId,
        ApiException $conflict
    ): string {
        $found = self::findDomain($api->getCustomer($customerId), $domain);
        if ($found === null) {
            throw $conflict;
        }
        // A website terminated earlier stays on the customer for a day before it is deleted.
        if (self::isBeingRemoved($found)) {
            throw new ModuleException(
                'The website ' . $domain . ' is being removed from CookiePal, so it can\'t be added again yet. '
                . 'Try again tomorrow, or contact CookiePal support (' . self::SUPPORT_EMAIL . ').'
            );
        }
        $domainId = (string) $found['domainId'];
        $owner = $store->findOtherLiveForWebsite($customerId, $domainId, $serviceId);
        if ($owner !== null) {
            throw new ModuleException(
                'This website already belongs to service #' . $owner->serviceId . '. Terminate that service first, '
                . 'or cancel this order.'
            );
        }
        self::setPlan($api, $customerId, $domainId, $plan);

        return $domainId;
    }

    /**
     * POST /external/customer for a client with no live CookiePal service. A 409 is never taken as
     * "this agency already has it": the API answers the same for a domain on any other customer,
     * and that customer must not end up on this service. A lost answer is recovered by the API instead:
     * the same idempotency key gets the first answer back as a 200.
     *
     * @param array<string, mixed> $params
     *
     * @return array{0: string, 1: string} customerId, domainId
     */
    private static function createCustomer(
        ApiClient $api,
        ServiceStore $store,
        array $params,
        int $serverId,
        int $clientId,
        string $domain,
        string $plan,
        ?string $idempotencyKey
    ): array {
        $client = is_array($params['clientsdetails'] ?? null) ? $params['clientsdetails'] : [];
        $email = trim(self::stringParam($client, 'email'));
        if ($email === '') {
            throw new ModuleException('The client has no email address. Add one to the client profile first.');
        }
        $name = trim(self::stringParam($client, 'companyname'));
        if ($name === '') {
            $first = trim(self::stringParam($client, 'firstname'));
            $name = trim($first . ' ' . trim(self::stringParam($client, 'lastname')));
        }
        if ($name === '') {
            $name = $email;
        }

        try {
            $result = $api->createCustomer($name, $email, $domain, $plan, $idempotencyKey);
        } catch (ApiException $e) {
            $elsewhere = $e->is(409, 'DOMAIN_REGISTERED_ELSEWHERE');
            if (!$elsewhere && !$e->is(409, 'USER_EXISTS')) {
                throw $e;
            }
            // CookiePal deletes a terminated customer a day later; until then it holds the domain and the email.
            if ($elsewhere) {
                throw new ModuleException(self::elsewhereMessage($store, $clientId, $domain));
            }
            $elsewhereLive = $clientId > 0 ? $store->findLiveForClientOnOtherServer($clientId, $serverId) : null;
            if ($elsewhereLive !== null) {
                // The client's CookiePal user belongs to another server's agency key; this one can't reuse it.
                throw new ModuleException(
                    'The email ' . $email . ' already has a CookiePal account through this client\'s service #'
                    . $elsewhereLive->serviceId . ', which is on another CookiePal server (WHMCS server #'
                    . $elsewhereLive->serverId . '). Put the client\'s CookiePal products on the same server, or '
                    . 'contact CookiePal support (' . self::SUPPORT_EMAIL . ').'
                );
            }
            $recent = self::recentlyTerminated($store, $clientId, null);
            if ($recent !== null && self::isCustomerBeingRemoved($api, $recent->customerId)) {
                throw new ModuleException(self::stillBeingRemovedMessage($recent));
            }
            if ($recent !== null) {
                // Terminate removed only the website: the customer has other websites, so its user stays.
                throw new ModuleException(
                    'The email ' . $email . ' belongs to the CookiePal customer of this client\'s service #'
                    . $recent->serviceId . ', which CookiePal kept when that service was terminated because it has '
                    . 'other websites. Contact CookiePal support (' . self::SUPPORT_EMAIL . ') with both service IDs.'
                );
            }
            throw new ModuleException(
                'The email ' . $email . ' already has its own CookiePal account. Ask CookiePal support ('
                . self::SUPPORT_EMAIL . ') to link that account to your agency, then run Create again.'
            );
        }

        $customerId = $result['customerId'] ?? null;
        if (!is_string($customerId) || $customerId === '') {
            throw new ApiException(201, 'CookiePal returned no customerId.', ApiException::BAD_RESPONSE);
        }

        return [$customerId, self::domainIdFrom($result)];
    }

    /** The client's service for this domain (or any, for a null domain) terminated in the last two days. */
    private static function recentlyTerminated(ServiceStore $store, int $clientId, ?string $domain): ?ServiceRecord
    {
        return $clientId > 0
            ? $store->findTerminatedForClientSince($clientId, ServiceStore::now(-self::REMOVAL_WINDOW_SECONDS), $domain)
            : null;
    }

    /** For a 409 DOMAIN_REGISTERED_ELSEWHERE: the client's own website being removed, or another account's. */
    private static function elsewhereMessage(ServiceStore $store, int $clientId, string $domain): string
    {
        $recent = self::recentlyTerminated($store, $clientId, $domain);

        return $recent !== null ? self::stillBeingRemovedMessage($recent) : self::registeredElsewhereMessage($domain);
    }

    private static function stillBeingRemovedMessage(ServiceRecord $recent): string
    {
        return 'This client\'s CookiePal service #' . $recent->serviceId
            . ' was terminated on ' . $recent->terminatedAt
            . ' UTC, and CookiePal is still removing it, which takes about a day. Run Create again after that, '
            . 'or contact CookiePal support (' . self::SUPPORT_EMAIL . ').';
    }

    /**
     * True when the customer is gone, or has no website that keeps it: deleting a customer makes all its
     * websites inactive. A failed read counts as being removed, which keeps the message shown before this check.
     */
    private static function isCustomerBeingRemoved(ApiClient $api, string $customerId): bool
    {
        try {
            $customer = $api->getCustomer($customerId);
        } catch (ApiException) {
            return true;
        }
        foreach (self::domainsOf($customer) as $domain) {
            if (self::isUsable($domain)) {
                return false;
            }
        }

        return true;
    }

    /** Saves the new ids, and leaves them in the Activity Log if the save fails. */
    private static function saveCreated(
        ServiceStore $store,
        int $serviceId,
        int $serverId,
        int $clientId,
        string $customerId,
        string $domainId
    ): void {
        try {
            $store->save($serviceId, $serverId, $clientId, $customerId, $domainId);
        } catch (\Throwable $e) {
            Logger::activity(
                'service #' . $serviceId . ' got CookiePal customer ' . $customerId . ' and website ' . $domainId
                . ', but the module couldn\'t save them: ' . $e->getMessage()
            );
            throw $e;
        }
    }

    private static function setPlan(ApiClient $api, string $customerId, string $domainId, string $plan): void
    {
        try {
            $api->setPlan($customerId, $domainId, $plan);
        } catch (ApiException $e) {
            $message = $e->getStatus() === 409 ? (self::PLAN_CONFLICTS[$e->getErrorCode()] ?? null) : null;
            if ($message !== null) {
                throw new ModuleException($message);
            }
            throw $e;
        }
    }

    /** Runs a DELETE; a 404 means it is already gone. */
    private static function ignoreNotFound(callable $delete): void
    {
        try {
            $delete();
        } catch (ApiException $e) {
            if (!$e->is(404)) {
                throw $e;
            }
        }
    }

    private static function registeredElsewhereMessage(string $domain): string
    {
        return 'The domain ' . $domain . ' is already registered to another CookiePal account. The customer can '
            . 'remove it from that account, or ask CookiePal support (' . self::SUPPORT_EMAIL . ') to move it '
            . 'to your agency.';
    }

    /**
     * The `domain.domainId` of a create answer.
     *
     * @param array<mixed> $result
     */
    private static function domainIdFrom(array $result): string
    {
        $domain = $result['domain'] ?? null;
        $domainId = is_array($domain) ? ($domain['domainId'] ?? null) : null;
        if (!is_string($domainId) || $domainId === '') {
            throw new ApiException(201, 'CookiePal returned no domainId.', ApiException::BAD_RESPONSE);
        }

        return $domainId;
    }

    /**
     * @param array<mixed> $customer
     *
     * @return list<array<mixed>>
     */
    private static function domainsOf(array $customer): array
    {
        $domains = $customer['domains'] ?? [];

        return is_array($domains) ? array_values(array_filter($domains, 'is_array')) : [];
    }

    /**
     * @param array<mixed> $customer
     *
     * @return array<mixed>|null
     */
    private static function findDomain(array $customer, string $domainId): ?array
    {
        foreach (self::domainsOf($customer) as $domain) {
            if (is_string($domain['domainId'] ?? null) && strcasecmp($domain['domainId'], $domainId) === 0) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Scheduled for deletion. Not every inactive website is: CookiePal deactivates websites for other
     * reasons too, and such a website stays until something deletes it.
     *
     * @param array<mixed> $domain
     */
    private static function isBeingRemoved(array $domain): bool
    {
        return self::websiteFacts($domain)['scheduledDeletion'] !== null;
    }

    /**
     * Active and not scheduled for deletion: a website that keeps its customer. A domain without `status`
     * is active.
     *
     * @param array<mixed> $domain
     */
    private static function isUsable(array $domain): bool
    {
        $facts = self::websiteFacts($domain);

        return $facts['active'] && $facts['scheduledDeletion'] === null;
    }

    /**
     * What both views show, from one domain item of GET /external/customers/{c}.
     *
     * @param array<mixed> $domain
     *
     * @return array{handler: string, plan: string, verification: string, installed: bool, suspended: bool,
     *     active: bool, scheduledDeletion: string|null}
     */
    private static function websiteFacts(array $domain): array
    {
        $verification = is_string($domain['verification'] ?? null) ? $domain['verification'] : 'not_verified';
        $scheduled = $domain['scheduledDeletion'] ?? null;

        return [
            'handler' => is_string($domain['handler'] ?? null) ? $domain['handler'] : '',
            'plan' => Plan::fromApi($domain['plan'] ?? null),
            'verification' => $verification,
            'installed' => $verification === 'verified',
            'suspended' => ($domain['suspended'] ?? false) === true,
            'active' => ($domain['status'] ?? 'active') === 'active',
            'scheduledDeletion' => is_string($scheduled) && $scheduled !== '' ? $scheduled : null,
        ];
    }

    private static function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
