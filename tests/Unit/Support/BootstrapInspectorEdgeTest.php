<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\BootstrapInspector;
use Illuminate\Filesystem\Filesystem;

it('returns false/inputs when Application::configure chain is absent', function (): void {
    $fs = new Filesystem();
    $bootstrap = base_path('bootstrap');
    $fs->ensureDirectoryExists($bootstrap);

    $src = <<<'PHP'
    <?php
    // No Application::configure here
    $app = new stdClass();
    PHP;

    $fs->put(base_path('bootstrap/app.php'), $src);

    $inspector = new BootstrapInspector($fs);

    expect($inspector->providerInsideConfigureChain('Foo\\Bar::class'))->toBeFalse()
        ->and($inspector->hasStandaloneWithProvidersBlock())->toBeFalse()
        ->and($inspector->missingRoutingKeys(['web','api']))->toBe(['web','api']);
});

it('returns full inputs from missingRoutingKeys when withRouting is absent', function (): void {
    $fs = new Filesystem();
    $bootstrap = base_path('bootstrap');
    $fs->ensureDirectoryExists($bootstrap);

    $src = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;
    return Application::configure(basePath: __DIR__)
        // no withRouting call present here
        ->create();
    PHP;

    $fs->put(base_path('bootstrap/app.php'), $src);

    $inspector = new BootstrapInspector($fs);

    $keys = ['web','api','health'];
    expect($inspector->missingRoutingKeys($keys))->toBe($keys);
});
