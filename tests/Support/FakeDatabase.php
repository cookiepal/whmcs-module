<?php

declare(strict_types=1);

namespace CookiePal\Tests\Support;

/**
 * An in-memory stand-in for the slice of Illuminate's query and schema builders that
 * ServiceStore uses. Querying a table that was never created throws, as MySQL would.
 */
final class FakeDatabase
{
    /** @var array<string, list<array<string, mixed>>> */
    public static array $tables = [];

    /** @var array<string, list<array{type: string, name: string, arguments: list<mixed>, modifiers: list<string>}>> */
    public static array $columns = [];

    public static int $createCalls = 0;

    /** When true, the next create() finds that another request created the table first. */
    public static bool $createRace = false;

    public static int $alterCalls = 0;

    /** When true, the next table() finds that another request added the column first. */
    public static bool $alterRace = false;

    public static function reset(): void
    {
        self::$tables = [];
        self::$columns = [];
        self::$createCalls = 0;
        self::$createRace = false;
        self::$alterCalls = 0;
        self::$alterRace = false;
    }

    /** @param array<string, mixed> $row */
    public static function seed(string $table, array $row): void
    {
        self::$tables[$table][] = $row;
    }
}
