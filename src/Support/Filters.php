<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

final readonly class Filters
{
    /** @var array<int,string> */
    private array $only;
    /** @var array<int,string> */
    private array $except;

    /**
     * @param array<int,string> $only
     * @param array<int,string> $except
     */
    public function __construct(array $only, array $except)
    {
        $this->only = $only;
        $this->except = $except;
    }

    public function allow(string $kind): bool
    {
        if ($kind === 'other') {
            return false;
        }
        if ($this->only !== [] && !in_array($kind, $this->only, true)) {
            return false;
        }
        if ($this->except !== [] && in_array($kind, $this->except, true)) {
            return false;
        }
        return true;
    }
}
