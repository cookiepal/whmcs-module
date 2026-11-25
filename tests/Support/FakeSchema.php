<?php

declare(strict_types=1);

namespace CookiePal\Tests\Support;

/** Capsule::schema(): hasTable() and create(). */
final class FakeSchema
{
    public function hasTable(string $table): bool
    {
        return array_key_exists($table, FakeDatabase::$tables);
    }

    /** @param callable(mixed): void $callback the module's closure, given a FakeBlueprint */
    public function create(string $table, callable $callback): void
    {
        if (FakeDatabase::$createRace) {
            FakeDatabase::$createRace = false;
            FakeDatabase::$tables[$table] = [];
        }
        if ($this->hasTable($table)) {
            throw new \RuntimeException("Base table or view already exists: {$table}");
        }
        FakeDatabase::$createCalls++;
        $blueprint = new FakeBlueprint();
        $callback($blueprint);
        FakeDatabase::$tables[$table] = [];
        FakeDatabase::$columns[$table] = array_map(fn (FakeColumn $c): array => $c->toArray(), $blueprint->columns);
    }
}
