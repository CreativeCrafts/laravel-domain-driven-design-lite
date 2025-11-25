<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\BootstrapInspector;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $fs = new Filesystem();
    $bootstrapDir = base_path('bootstrap');
    $fs->ensureDirectoryExists($bootstrapDir);
});

it('detects provider mentioned and inside configure chain and standalone block', function () {
    $fs = new Filesystem();
    $providerFqcn = 'Modules\\Blog\\Providers\\BlogServiceProvider::class';

    $src = <<<'PHP'
    <?php
    
    use Illuminate\Foundation\Application;
    
    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
            commands: __DIR__.'/../routes/console.php',
            health: '/up',
        )
        ->withProviders([
            Modules\Blog\Providers\BlogServiceProvider::class,
        ])
        ->create();
    
    // Also allow a standalone block elsewhere
    $app->withProviders([
        Modules\Blog\Providers\BlogServiceProvider::class,
    ]);
    PHP;

    $fs->put(base_path('bootstrap/app.php'), $src);

    $inspector = new BootstrapInspector($fs);

    expect($inspector->providerMentioned('Modules\\Blog\\Providers\\BlogServiceProvider::class'))->toBeTrue();
    expect($inspector->providerInsideConfigureChain('Modules\\Blog\\Providers\\BlogServiceProvider::class'))->toBeTrue();
    expect($inspector->hasStandaloneWithProvidersBlock())->toBeTrue();
});

it('computes missing routing keys when absent', function () {
    $fs = new Filesystem();
    $src = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;
    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php'
        )
        ->create();
    PHP;

    $fs->put(base_path('bootstrap/app.php'), $src);

    $inspector = new BootstrapInspector($fs);

    $missing = $inspector->missingRoutingKeys(['web', 'health', 'commands']);
    sort($missing);
    expect($missing)->toBe(['commands', 'health']);
});
