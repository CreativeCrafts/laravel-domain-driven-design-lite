<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\BootstrapInspector;
use Illuminate\Filesystem\Filesystem;

it('returns false when Application::configure chain lacks ->create()', function (): void {
    $fs = new Filesystem();
    $bootstrap = base_path('bootstrap');
    $fs->ensureDirectoryExists($bootstrap);

    $src = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;
    // Chain without ->create()
    Application::configure(basePath: __DIR__)
        ->withProviders([
            Modules\Demo\Providers\DemoServiceProvider::class,
        ]);
    PHP;

    $fs->put(base_path('bootstrap/app.php'), $src);

    $inspector = new BootstrapInspector($fs);

    expect($inspector->providerInsideConfigureChain('Modules\\Demo\\Providers\\DemoServiceProvider::class'))
        ->toBeFalse();
});
