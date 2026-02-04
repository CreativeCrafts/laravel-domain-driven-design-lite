<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('lists modules with health in json', function (): void {
    $fs = new Filesystem();

    $module = 'Billing';
    $modulesRoot = base_path('modules');
    $moduleDir = base_path("modules/{$module}");
    $fs->ensureDirectoryExists($modulesRoot);
    if (!$fs->isDirectory($moduleDir)) {
        $fs->ensureDirectoryExists($moduleDir);
    }

    $exit = $this->artisan('ddd-lite:modules:list', [
        '--json' => true,
        '--with-health' => true,
    ])->run();

    expect($exit)->toBe(0);
});
