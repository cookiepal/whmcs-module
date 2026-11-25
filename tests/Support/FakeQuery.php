<?php

declare(strict_types=1);

namespace CookiePal\Tests\Support;

/** Capsule::table(): the query builder methods ServiceStore uses. */
final class FakeQuery
{
    /** @var list<\Closure(array<string, mixed>): bool> */
    private array $wheres = [];

    private ?string $orderColumn = null;
    private string $orderDirection = 'asc';

    public function __construct(private readonly string $table)
    {
        if (!array_key_exists($table, FakeDatabase::$tables)) {
            throw new \RuntimeException("Base table or view not found: {$table}");
        }
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operatorOrValue] : [$operatorOrValue, $value];
        $this->wheres[] = match ($operator) {
            '=' => fn (array $row): bool => ($row[$column] ?? null) == $value,
            '!=', '<>' => fn (array $row): bool => ($row[$column] ?? null) != $value,
            '>=' => fn (array $row): bool => ($row[$column] ?? null) !== null
                && strcmp((string) $row[$column], (string) $value) >= 0,
            default => throw new \LogicException("Operator {$operator} not faked"),
        };

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = fn (array $row): bool => ($row[$column] ?? null) === null;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderColumn = $column;
        $this->orderDirection = strtolower($direction);

        return $this;
    }

    public function first(): ?\stdClass
    {
        $rows = $this->matching();

        return $rows === [] ? null : (object) reset($rows);
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        $count = 0;
        foreach (array_keys($this->matching()) as $index) {
            FakeDatabase::$tables[$this->table][$index] = array_merge(
                FakeDatabase::$tables[$this->table][$index],
                $values
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public function updateOrInsert(array $attributes, array $values): bool
    {
        foreach ($attributes as $column => $value) {
            $this->where($column, $value);
        }
        if ($this->update($values) === 0) {
            FakeDatabase::$tables[$this->table][] = array_merge($attributes, $values);
        }

        return true;
    }

    /** @return array<int, array<string, mixed>> rows keyed by their index in the table */
    private function matching(): array
    {
        $rows = array_filter(
            FakeDatabase::$tables[$this->table],
            function (array $row): bool {
                foreach ($this->wheres as $where) {
                    if (!$where($row)) {
                        return false;
                    }
                }

                return true;
            }
        );
        if ($this->orderColumn !== null) {
            $column = $this->orderColumn;
            $sign = $this->orderDirection === 'desc' ? -1 : 1;
            uasort(
                $rows,
                fn (array $a, array $b): int => $sign * strcmp(
                    (string) ($a[$column] ?? ''),
                    (string) ($b[$column] ?? '')
                )
            );
        }

        return $rows;
    }
}
