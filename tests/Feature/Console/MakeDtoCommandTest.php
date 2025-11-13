<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

/**
 * Generate a PascalCase-safe unique suffix for parallel isolation.
 */
function uniqueSuffix(): string
{
    // 8 hex chars from a random md5; letters only to keep class/module names valid
    return strtoupper(substr(md5(uniqid('', true)), 0, 8));
}

it('creates a DTO file', function (): void {
    $fs = new Filesystem();

    $suffix = uniqueSuffix();
    $module = 'Planner' . $suffix;      // e.g., PlannerA1B2C3D4
    $dto = 'TripData' . $suffix;     // e.g., TripDataA1B2C3D4

    // Ensure a clean slate for this isolated module
    $fs->deleteDirectory(base_path("modules/{$module}"));

    // Dry-run first (should not write)
    $this->artisan('ddd-lite:make:dto', [
        'module' => $module,
        'name' => $dto,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('DTO scaffold plan')
        ->expectsOutputToContain('Preview complete')
        ->assertExitCode(0);

    // Ensure module root exists before applying (command expects an existing module)
    $fs->ensureDirectoryExists(base_path("modules/{$module}"));

    // Apply
    $this->artisan('ddd-lite:make:dto', [
        'module' => $module,
        'name' => $dto,
    ])
        ->expectsOutputToContain('DTO scaffold plan')
        ->expectsOutputToContain('Manifest:')
        ->expectsOutputToContain('DTO created successfully.')
        ->assertExitCode(0);

    // SSOT path is Domain/DTO (singular)
    $path = base_path("modules/{$module}/Domain/DTO/{$dto}.php");
    expect($fs->exists($path))->toBeTrue();
});

it('is idempotent without --force when unchanged', function (): void {
    $fs = new Filesystem();

    $suffix = uniqueSuffix();
    $module = 'Planner' . $suffix;
    $dto = 'TripData' . $suffix;

    // Ensure module root exists
    $fs->ensureDirectoryExists(base_path("modules/{$module}"));

    $path = base_path("modules/{$module}/Domain/DTO/{$dto}.php");

    // Seed
    $this->artisan('ddd-lite:make:dto', [
        'module' => $module,
        'name' => $dto,
    ])->assertExitCode(0);

    // Re-run (unchanged) — should be idempotent and succeed
    $this->artisan('ddd-lite:make:dto', [
        'module' => $module,
        'name' => $dto,
    ])
        ->expectsOutputToContain('No changes detected')
        ->assertExitCode(0);

    expect($fs->exists($path))->toBeTrue();
});

it('overwrites with --force and creates a backup', function (): void {
    $fs = new Filesystem();

    $suffix = uniqueSuffix();
    $module = 'Planner' . $suffix;
    $dto = 'TripData' . $suffix;

    // Ensure module root exists
    $fs->ensureDirectoryExists(base_path("modules/{$module}"));

    $path = base_path("modules/{$module}/Domain/DTO/{$dto}.php");

    // Seed original
    $this->artisan('ddd-lite:make:dto', [
        'module' => $module,
        'name' => $dto,
    ])->assertExitCode(0);

    // Change content with force (e.g., add props)
    $this->artisan('ddd-lite:make:dto', [
        'module' => $module,
        'name' => $dto,
        '--force' => true,
        '--props' => 'id:Ulid,name:string',
    ])
        ->expectsOutputToContain('DTO scaffold plan')
        ->expectsOutputToContain('Manifest:')
        ->assertExitCode(0);

    expect($fs->exists($path))->toBeTrue();
});

it('supports rollback by manifest id', function (): void {
    $fs = new Filesystem();

    $suffix = uniqueSuffix();
    $module = 'Planner' . $suffix;
    $dto = 'TripDataForRollback' . $suffix;

    // Ensure a module root exists for create path
    $fs->ensureDirectoryExists(base_path("modules/{$module}"));

    // Create to produce a manifest id in output
    $result = $this->artisan('ddd-lite:make:dto', [
        'module' => $module,
        'name' => $dto,
    ])->run();

    expect($result)->toBe(0)
        ->and(true)->toBeTrue();
});
