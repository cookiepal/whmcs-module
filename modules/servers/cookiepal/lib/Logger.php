<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The module's writes to WHMCS's Module Log and Activity Log.
 */
final class Logger
{
    public const MODULE = 'cookiepal';

    /**
     * One entry in the Module Log. WHMCS replaces each value in $secrets with asterisks.
     *
     * @param array<mixed>|string $processed
     * @param list<string> $secrets
     */
    public static function call(
        string $action,
        string $request,
        string $response,
        array|string $processed,
        array $secrets
    ): void {
        logModuleCall(self::MODULE, $action, $request, $response, $processed, array_values(array_filter(
            $secrets,
            fn (string $secret): bool => $secret !== ''
        )));
    }

    /**
     * An exception the module didn't expect, with its trace, in the Module Log.
     *
     * @param array<string, mixed> $params
     */
    public static function unexpected(string $action, array $params, \Throwable $e): void
    {
        self::call(
            $action,
            'service ' . self::serviceId($params),
            get_class($e) . ': ' . $e->getMessage(),
            $e->getTraceAsString(),
            [is_string($params['serverpassword'] ?? null) ? trim($params['serverpassword']) : '']
        );
    }

    /** A line in WHMCS's Activity Log. */
    public static function activity(string $message): void
    {
        logActivity('CookiePal: ' . $message);
    }

    /** @param array<string, mixed> $params */
    private static function serviceId(array $params): int
    {
        return is_numeric($params['serviceid'] ?? null) ? (int) $params['serviceid'] : 0;
    }
}
