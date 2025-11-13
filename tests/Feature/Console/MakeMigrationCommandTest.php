<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('creates a create-table migration by name inference', function (): void {
    $fs = new Filesystem();

    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $module = 'Planner' . $suffix;
    $dir = base_path("modules/{$module}/database/migrations");
    $fs->ensureDirectoryExists($dir);

    $exit = $this->artisan('ddd-lite:make:migration', [
        'module' => $module,
        'name' => 'create_trips_table',
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0);

    $exit2 = $this->artisan('ddd-lite:make:migration', [
        'module' => $module,
        'name' => 'create_trips_table',
    ])->run();

    expect($exit2)->toBe(0);

    $paths = glob($dir . '/*_create_trips_table.php') ?: [];
    expect($paths)->not()->toBe([]);
    $code = (string)file_get_contents($paths[0]);
    expect($code)->toContain("Schema::create('trips'");
});

it('creates an update migration when not a create', function (): void {
    $fs = new Filesystem();

    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $module = 'Planner' . $suffix;
    $dir = base_path("modules/{$module}/database/migrations");
    $fs->ensureDirectoryExists($dir);

    $exit = $this->artisan('ddd-lite:make:migration', [
        'module' => $module,
        'name' => 'add_flags_to_trips_table',
        '--table' => 'trips',
    ])->run();

    expect($exit)->toBe(0);

    $paths = glob($dir . '/*_add_flags_to_trips_table.php') ?: [];
    expect($paths)->not()->toBe([]);
    $code = (string)file_get_contents($paths[0]);
    expect($code)->toContain("Schema::table('trips'");
});

it('supports rollback', function (): void {
    $fs = new Filesystem();

    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $module = 'Planner' . $suffix;
    $dir = base_path("modules/{$module}/database/migrations");
    $fs->ensureDirectoryExists($dir);

    // Create target migration
    $exit = $this->artisan('ddd-lite:make:migration', [
        'module' => $module,
        'name' => 'create_notes_table',
    ])->run();

    expect($exit)->toBe(0);

    // Find the actual filename generated
    $paths = glob($dir . '/*_create_notes_table.php') ?: [];
    expect($paths)->not()->toBe([]);
    $createdPathAbs = $paths[0];
    $createdRel = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $createdPathAbs), DIRECTORY_SEPARATOR);

    // Locate the manifest that references this file (robust in parallel)
    $manifestFiles = glob(storage_path('app/ddd-lite_scaffold/manifests/*.json')) ?: [];
    $manifestId = null;
    foreach ($manifestFiles as $mf) {
        // In parallel runs, a manifest may disappear between discovery and read; guard accordingly.
        if (!is_file($mf)) {
            continue;
        }
        $raw = @file_get_contents($mf);
        if ($raw === false || $raw === '') {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }
        $actions = $data['actions'] ?? $data['ops'] ?? [];
        if (!is_array($actions)) {
            continue;
        }
        foreach ($actions as $action) {
            $p = $action['path'] ?? $action['target'] ?? null;
            if ($p === $createdRel) {
                $manifestId = $data['id'] ?? pathinfo($mf, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($manifestId)->not->toBeNull();

    $this->artisan('ddd-lite:make:migration', [
        '--rollback' => $manifestId,
    ])->run();

    // File should be gone after rollback
    $paths = glob($dir . '/*_create_notes_table.php') ?: [];
    expect($paths)->toBe([]);
});