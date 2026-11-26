<?php

declare(strict_types=1);

namespace CookiePal\Tests\Support;

/** What the module wrote to WHMCS's Module Log and Activity Log (see tests/Stubs/functions.php). */
final class ModuleLog
{
    /**
     * @var list<array{module: string, action: string, request: mixed, response: mixed, processed: mixed,
     *     replaceVars: list<string>}>
     */
    public static array $calls = [];

    /** @var list<string> */
    public static array $activity = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$activity = [];
    }

    /**
     * A Module Log entry as an admin reads it: WHMCS replaces each of replaceVars with asterisks.
     *
     * @param array{request: mixed, response: mixed, processed: mixed, replaceVars: list<string>} $call
     */
    public static function asShown(array $call): string
    {
        $text = implode("\n", array_map(
            fn (mixed $part): string => is_string($part) ? $part : (string) json_encode($part, JSON_UNESCAPED_SLASHES),
            [$call['request'], $call['response'], $call['processed']]
        ));

        return str_replace($call['replaceVars'], '***', $text);
    }
}
