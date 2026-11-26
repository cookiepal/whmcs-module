<?php

declare(strict_types=1);

namespace CookiePal\Tests\Support;

/** Capsule::schema(): hasTable(), hasColumn(), create() and table() (adding columns). */
final class FakeSchema
{
    public function hasTable(string $table): bool
    {
        return array_key_exists($table, FakeDatabase::$tables);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, array_column(FakeDatabase::$columns[$table] ?? [], 'name'), true);
    }

    /**
     * Adds the blueprint's columns to an existing table; existing rows get null, as before a fill.
     *
     * @param callable(mixed): void $callback the module's closure, given a FakeBlueprint
     */
    public function table(string $table, callable $callback): void
    {
        $blueprint = new FakeBlueprint();
        $callback($blueprint);
        foreach ($blueprint->columns as $column) {
            $name = $column->toArray()['name'];
            if (FakeDatabase::$alterRace) {
                FakeDatabase::$alterRace = false;
                FakeDatabase::$columns[$table][] = $column->toArray();
            }
            if ($this->hasColumn($table, $name)) {
                throw new \RuntimeException("Duplicate column name '{$name}'");
            }
            FakeDatabase::$alterCalls++;
            FakeDatabase::$columns[$table][] = $column->toArray();
            foreach (FakeDatabase::$tables[$table] as $index => $row) {
                FakeDatabase::$tables[$table][$index] = $row + [$name => null];
            }
        }
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
