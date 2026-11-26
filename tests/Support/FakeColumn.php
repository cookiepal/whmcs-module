<?php

declare(strict_types=1);

namespace CookiePal\Tests\Support;

/** One column of a FakeBlueprint, with its modifiers (primary, index, nullable). */
final class FakeColumn
{
    /** @var list<string> */
    private array $modifiers = [];

    /** @param list<mixed> $arguments */
    public function __construct(
        private readonly string $type,
        private readonly string $name,
        private readonly array $arguments
    ) {
    }

    /** @param list<mixed> $args */
    public function __call(string $modifier, array $args): self
    {
        $this->modifiers[] = $modifier;

        return $this;
    }

    /** @return array{type: string, name: string, arguments: list<mixed>, modifiers: list<string>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'modifiers' => $this->modifiers,
        ];
    }
}
