<?php

declare(strict_types=1);

use CookiePal\Tests\Support\ModuleLog;

/**
 * WHMCS's Module Log: records the call.
 *
 * @param array<mixed>|string $request
 * @param array<mixed>|string $response
 * @param array<mixed>|string $processed
 * @param list<string> $replaceVars
 */
function logModuleCall(
    string $module,
    string $action,
    array|string $request,
    array|string $response,
    array|string $processed = '',
    array $replaceVars = []
): void {
    ModuleLog::$calls[] = [
        'module' => $module,
        'action' => $action,
        'request' => $request,
        'response' => $response,
        'processed' => $processed,
        'replaceVars' => $replaceVars,
    ];
}

/** WHMCS's Activity Log: records the line. */
function logActivity(string $message, int $userId = 0): void
{
    ModuleLog::$activity[] = $message;
}
