<?php

declare(strict_types=1);

namespace WHMCS\Database;

use CookiePal\Tests\Support\FakeQuery;
use CookiePal\Tests\Support\FakeSchema;

/** WHMCS's Capsule facade, backed by the in-memory FakeDatabase. */
final class Capsule
{
    public static function schema(): FakeSchema
    {
        return new FakeSchema();
    }

    public static function table(string $table): FakeQuery
    {
        return new FakeQuery($table);
    }
}
