<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('creates a ULID-backed model with defaults', function (): void {
    $fs = new Filesystem();

    // Use a module name that is unique to this test file to avoid clashes
    // with other tests that might also work with a "Planner" module.
    $module = 'PlannerModelDemo';
    $moduleRoot = base_path("modules/{$module}/App/Models");
    $fs->ensureDirectoryExists($moduleRoot);

    $path = base_path("modules/{$module}/App/Models/Trip.php");
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $exit = $this->artisan('ddd-lite:make:model', [
        'module' => $module,
        'name' => 'Trip',
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0);

    $exit2 = $this->artisan('ddd-lite:make:model', [
        'module' => $module,
        'name' => 'Trip',
    ])->run();

    expect($exit2)->toBe(0);

    expect($fs->exists($path))->toBeTrue();

    $code = (string)$fs->get($path);
    expect($code)->toContain('use Illuminate\\Database\\Eloquent\\Concerns\\HasUlids;')
        ->and($code)->toContain("protected string \$table = 'trips';")
        ->and($code)->toContain('public bool $incrementing = false;')
        ->and($code)->toContain("protected string \$keyType = 'string';");
});

it('supports soft deletes, disables timestamps, and fillable list', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerModelDemo';
    $moduleRoot = base_path("modules/{$module}/App/Models");
    $fs->ensureDirectoryExists($moduleRoot);

    $path = base_path("modules/{$module}/App/Models/Itinerary.php");
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $exit = $this->artisan('ddd-lite:make:model', [
        'module' => $module,
        'name' => 'Itinerary',
        '--soft-deletes' => true,
        '--no-timestamps' => true,
        '--fillable' => 'name,starts_at,ends_at',
    ])->run();

    expect($exit)->toBe(0);

    $code = (string)$fs->get($path);
    expect($code)->toContain('use Illuminate\\Database\\Eloquent\\SoftDeletes;')
        ->and($code)->toContain('use HasUlids, SoftDeletes;')
        ->and($code)->toContain('public bool $timestamps = false;')
        ->and($code)->toContain("protected array \$fillable = ['name', 'starts_at', 'ends_at'];");
});

it('errors when both fillable and guarded are provided', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerModelDemo';
    $moduleRoot = base_path("modules/{$module}/App/Models");
    $fs->ensureDirectoryExists($moduleRoot);

    $this->artisan('ddd-lite:make:model', [
        'module' => $module,
        'name' => 'Budget',
        '--fillable' => 'amount',
        '--guarded' => 'amount',
    ])->assertExitCode(1);
});

it('supports rollback', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerModelDemo';
    $moduleRoot = base_path("modules/{$module}/App/Models");
    $fs->ensureDirectoryExists($moduleRoot);

    $path = base_path("modules/{$module}/App/Models/Note.php");
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $manifestDir = storage_path('app/ddd-lite_scaffold/manifests');
    $before = glob($manifestDir . '/*.json') ?: [];

    $this->artisan('ddd-lite:make:model', [
        'module' => $module,
        'name' => 'Note',
    ])->run();

    expect($fs->exists($path))->toBeTrue();

    $after = glob($manifestDir . '/*.json') ?: [];
    $new = array_values(array_diff($after, $before));

    $relPath = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path), DIRECTORY_SEPARATOR);
    $manifestId = null;

    foreach ($new as $file) {
        $data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $actions = $data['actions'] ?? [];
        foreach ($actions as $action) {
            if (
                ($action['type'] === 'create' || $action['type'] === 'update')
                && $action['path'] === $relPath
            ) {
                $manifestId = pathinfo($file, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($manifestId)->not->toBeNull();

    $this->artisan('ddd-lite:make:model', [
        '--rollback' => $manifestId,
    ])->run();

    expect($fs->exists($path))->toBeFalse();
});