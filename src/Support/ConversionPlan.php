<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

final class ConversionPlan
{
    /** @var array<int, MoveCandidate> */
    public array $items = [];

    public function add(MoveCandidate $c): void
    {
        $this->items[] = $c;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }
}
