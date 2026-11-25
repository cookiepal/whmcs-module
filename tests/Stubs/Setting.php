<?php

declare(strict_types=1);

namespace WHMCS\Config;

/** WHMCS's system settings. Tests set the values they need. */
final class Setting
{
    /** @var array<string, string> */
    public static array $values = [];

    public static function getValue(string $name): ?string
    {
        return self::$values[$name] ?? null;
    }
}
