<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('initializes a project with module, quality configs, and CI file', function (): void {
    $fs = new Filesystem();

    $module = 'Users';
    $moduleDir = base_path("modules/{$module}");
    $phpstan = base_path('phpstan.neon');
    $ciPath = base_path('.github/workflows/ddd-lite.yml');

    if ($fs->isDirectory($moduleDir)) {
        $fs->deleteDirectory($moduleDir);
    }
    if ($fs->exists($phpstan)) {
        $fs->delete($phpstan);
    }
    if ($fs->exists($ciPath)) {
        $fs->delete($ciPath);
    }

    $exit = $this->artisan('ddd-lite:init', [
        '--module' => $module,
        '--publish' => 'quality',
        '--ci' => 'write',
        '--ci-path' => '.github/workflows/ddd-lite.yml',
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->isDirectory($moduleDir))->toBeTrue()
        ->and($fs->exists($phpstan))->toBeTrue()
        ->and($fs->exists($ciPath))->toBeTrue();

    $ci = (string)$fs->get($ciPath);
    expect($ci)->toContain('ddd-lite:doctor-ci');
});
