<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Filters;

it('allows kinds based on only/except lists and forbids other by default', function (): void {
    // By default 'other' is not allowed
    $f = new Filters([], []);
    expect($f->allow('other'))->toBeFalse();

    // When only is empty and except empty, non-other kinds are allowed
    expect($f->allow('controllers'))->toBeTrue();

    // Only restricts to a subset
    $f2 = new Filters(['controllers','models'], []);
    expect($f2->allow('controllers'))->toBeTrue()
        ->and($f2->allow('models'))->toBeTrue()
        ->and($f2->allow('requests'))->toBeFalse();

    // Except excludes specified kinds
    $f3 = new Filters([], ['controllers']);
    expect($f3->allow('controllers'))->toBeFalse()
        ->and($f3->allow('models'))->toBeTrue();

    // Both only and except: only applies first, then except removes within that
    $f4 = new Filters(['controllers','models'], ['controllers']);
    expect($f4->allow('controllers'))->toBeFalse()
        ->and($f4->allow('models'))->toBeTrue()
        ->and($f4->allow('requests'))->toBeFalse();
});
