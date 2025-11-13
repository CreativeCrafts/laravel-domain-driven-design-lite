<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('does not create controller file on dry-run', function (): void {
    $fs = new Filesystem();
    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $module = 'Planner' . $suffix;

    $dir = base_path("modules/{$module}/App/Http/Controllers");
    $fs->ensureDirectoryExists($dir);

    $path = $dir . '/TripController.php';
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $exit = $this->artisan('ddd-lite:make:controller', [
        'module' => $module,
        'name' => 'Trip',
        '--resource' => true,
        '--inertia' => true,
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($path))->toBeFalse();
});

it('creates an Inertia resource controller', function (): void {
    $fs = new Filesystem();
    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $module = 'Planner' . $suffix;

    $dir = base_path("modules/{$module}/App/Http/Controllers");
    $fs->ensureDirectoryExists($dir);

    $path = $dir . '/TripController.php';
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $exit = $this->artisan('ddd-lite:make:controller', [
        'module' => $module,
        'name' => 'Trip',
        '--resource' => true,
        '--inertia' => true,
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0);

    $exit2 = $this->artisan('ddd-lite:make:controller', [
        'module' => $module,
        'name' => 'Trip',
        '--resource' => true,
        '--inertia' => true,
    ])->run();

    expect($exit2)->toBe(0);

    expect($fs->exists($path))->toBeTrue();

    $code = (string)$fs->get($path);
    expect($code)->toContain('namespace Modules\\' . $module . '\\App\\Http\\Controllers;')
        ->and($code)->toContain('final class TripController')
        ->and($code)->toContain('Inertia::render(\'Trip/Index\')')
        ->and($code)->toContain('Inertia::render(\'Trip/Create\')')
        ->and($code)->toContain('Inertia::render(\'Trip/Show\')')
        ->and($code)->toContain('Inertia::render(\'Trip/Edit\')');
});

it('is idempotent without --force when content matches', function (): void {
    $fs = new Filesystem();
    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $module = 'Planner' . $suffix;

    $dir = base_path("modules/{$module}/App/Http/Controllers");
    $fs->ensureDirectoryExists($dir);

    $path = $dir . '/TripController.php';
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $first = $this->artisan('ddd-lite:make:controller', [
        'module' => $module,
        'name' => 'Trip',
        '--resource' => true,
        '--inertia' => true,
    ])->run();

    expect($first)->toBe(0);

    $second = $this->artisan('ddd-lite:make:controller', [
        'module' => $module,
        'name' => 'Trip',
        '--resource' => true,
        '--inertia' => true,
    ])->run();

    expect($second)->toBe(0);
});

it('creates a minimal controller when not resource', function (): void {
    $fs = new Filesystem();
    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $module = 'Planner' . $suffix;

    $dir = base_path("modules/{$module}/App/Http/Controllers");
    $fs->ensureDirectoryExists($dir);

    $exit = $this->artisan('ddd-lite:make:controller', [
        'module' => $module,
        'name' => 'Note',
        '--inertia' => true,
    ])->run();

    expect($exit)->toBe(0);

    $path = $dir . '/NoteController.php';
    $code = (string)$fs->get($path);

    expect($code)->toContain('final class NoteController')
        ->and($code)->toContain('public function index(')
        ->and($code)->not->toContain('public function create(')
        ->and($code)->not->toContain('public function store(')
        ->and($code)->not->toContain('public function destroy(');
});

it('supports rollback', function (): void {
    $fs = new Filesystem();
    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $module = 'Planner' . $suffix;

    $dir = base_path("modules/{$module}/App/Http/Controllers");
    $fs->ensureDirectoryExists($dir);

    $path = $dir . '/ArchiveController.php';
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $exit = $this->artisan('ddd-lite:make:controller', [
        'module' => $module,
        'name' => 'Archive',
        '--resource' => true,
        '--inertia' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($path))->toBeTrue();

    // Locate this test's manifest by matching the action path for our controller
    $manifestDir = storage_path('app/ddd-lite_scaffold/manifests');
    $files = glob($manifestDir . '/*.json') ?: [];

    $targetRelPath = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path), DIRECTORY_SEPARATOR);
    $manifestId = null;

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $json = @file_get_contents($file);
        if ($json === false || $json === '') {
            continue;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            continue;
        }
        $actions = $data['actions'] ?? $data['ops'] ?? [];
        if (!is_array($actions)) {
            continue;
        }
        foreach ($actions as $action) {
            $p = $action['path'] ?? $action['target'] ?? null;
            if ($p === $targetRelPath) {
                $manifestId = $data['id'] ?? pathinfo($file, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($manifestId)->not->toBeNull();

    $rollbackExit = $this->artisan('ddd-lite:make:controller', [
        '--rollback' => $manifestId,
    ])->run();

    expect($rollbackExit)->toBe(0)
        ->and($fs->exists($path))->toBeFalse();
});