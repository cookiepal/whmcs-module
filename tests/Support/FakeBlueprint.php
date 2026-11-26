<?php

declare(strict_types=1);

namespace CookiePal\Tests\Support;

/** The table blueprint: each column method records its type, name and arguments (such as a length). */
final class FakeBlueprint
{
    /** @var list<FakeColumn> */
    public array $columns = [];

    /** @param list<mixed> $args */
    public function __call(string $type, array $args): FakeColumn
    {
        $column = new FakeColumn($type, (string) array_shift($args), $args);
        $this->columns[] = $column;

        return $column;
    }
}
