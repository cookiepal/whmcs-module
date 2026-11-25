<?php

/**
 * CookiePal for WHMCS: sells the CookiePal cookie banner as a WHMCS product.
 *
 * The server record holds the CookiePal agency API host (hostname) and the agency's API key
 * (password). Each WHMCS service is one CookiePal website; a client's services share one
 * CookiePal customer. See README.md for the host's guide.
 *
 * @copyright Copyright (c) 2026 CookiePal
 * @license   MIT
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ApiException.php';
require_once __DIR__ . '/lib/ModuleException.php';
require_once __DIR__ . '/lib/Transport.php';
require_once __DIR__ . '/lib/CurlTransport.php';
require_once __DIR__ . '/lib/Hostname.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/ServiceRecord.php';
require_once __DIR__ . '/lib/ServiceStore.php';
require_once __DIR__ . '/lib/Plan.php';
require_once __DIR__ . '/lib/Snippet.php';
require_once __DIR__ . '/lib/Lang.php';
require_once __DIR__ . '/lib/Module.php';

use WHMCS\Module\Server\CookiePal\Module;
use WHMCS\Module\Server\CookiePal\Plan;

/** @return array<string, mixed> */
function cookiepal_MetaData(): array
{
    return [
        'DisplayName' => 'CookiePal',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'ServiceSingleSignOnLabel' => 'Open CookiePal dashboard',
    ];
}

/** @return array<string, array<string, mixed>> */
function cookiepal_ConfigOptions(): array
{
    return [
        'Plan' => [
            'Type' => 'dropdown',
            'Options' => Plan::LABELS,
            'Default' => 'starter',
            'Description' => 'The CookiePal plan each service of this product gets',
        ],
    ];
}

/** @param array<string, mixed> $params */
function cookiepal_CreateAccount(array $params): string
{
    return Module::createAccount($params);
}

/** @param array<string, mixed> $params */
function cookiepal_SuspendAccount(array $params): string
{
    return Module::suspendAccount($params);
}

/** @param array<string, mixed> $params */
function cookiepal_UnsuspendAccount(array $params): string
{
    return Module::unsuspendAccount($params);
}

/** @param array<string, mixed> $params */
function cookiepal_TerminateAccount(array $params): string
{
    return Module::terminateAccount($params);
}

/** @param array<string, mixed> $params */
function cookiepal_ChangePackage(array $params): string
{
    return Module::changePackage($params);
}

/**
 * @param array<string, mixed> $params
 *
 * @return array{success: bool, error: string}
 */
function cookiepal_TestConnection(array $params): array
{
    return Module::testConnection($params);
}

/**
 * @param array<string, mixed> $params
 *
 * @return array{success: bool, redirectTo?: string, errorMsg?: string}
 */
function cookiepal_ServiceSingleSignOn(array $params): array
{
    return Module::serviceSingleSignOn($params);
}

/**
 * @param array<string, mixed> $params
 *
 * @return array{tabOverviewModuleOutputTemplate: string, templateVariables: array<string, mixed>}
 */
function cookiepal_ClientArea(array $params): array
{
    return Module::clientArea($params);
}

/**
 * @param array<string, mixed> $params
 *
 * @return array<string, string>
 */
function cookiepal_AdminServicesTabFields(array $params): array
{
    return Module::adminServicesTabFields($params);
}

/** @return array<string, string> */
function cookiepal_AdminCustomButtonArray(): array
{
    return Module::adminCustomButtonArray();
}

/** @param array<string, mixed> $params */
function cookiepal_StartScan(array $params): string
{
    return Module::startScan($params);
}

/** @param array<string, mixed> $params */
function cookiepal_RefreshStatus(array $params): string
{
    return Module::refreshStatus($params);
}
