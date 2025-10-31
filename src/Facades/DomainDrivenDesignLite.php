<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \CreativeCrafts\DomainDrivenDesignLite\DomainDrivenDesignLite
 */
class DomainDrivenDesignLite extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \CreativeCrafts\DomainDrivenDesignLite\DomainDrivenDesignLite::class;
    }
}
