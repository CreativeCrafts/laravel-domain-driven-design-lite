<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('creates an Aggregator', function (): void {
    $fs = new Filesystem();
    $module = 'Planner';
    $dir = base_path("modules/{$module}/Domain/Aggregators");
    $fs->ensureDirectoryExists($dir);

    $path = $dir . '/TripListAggregator.php';
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $exit = $this->artisan('ddd-lite:make:aggregator', [
        'module' => $module,
        'name' => 'TripList',
    ])->run();

    expect($exit)->toBe(0);
    expect($fs->exists($path))->toBeTrue();

    $code = (string)$fs->get($path);
    expect($code)->toContain('namespace Modules\\Planner\\Domain\\Aggregators;')
        ->and($code)->toContain('final class TripListAggregator')
        ->and($code)->toContain('public static function fromQueries(')
        ->and($code)->toContain('public function toArray(): array');
});

it('dry-run prints a plan and performs no writes', function (): void {
    $fs = new Filesystem();

    $path = base_path('modules/Planner/Domain/Aggregators/TripListAggregator.php');
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $exit = $this->artisan('ddd-lite:make:aggregator', [
        'module' => 'Planner',
        'name' => 'TripList',
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($path))->toBeFalse();
});

it('is idempotent when regenerated unchanged', function (): void {
    $fs = new Filesystem();

    $path = base_path('modules/Planner/Domain/Aggregators/TripListAggregator.php');
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $first = $this->artisan('ddd-lite:make:aggregator', [
        'module' => 'Planner',
        'name' => 'TripList',
    ])->run();

    expect($first)->toBe(0);

    $second = $this->artisan('ddd-lite:make:aggregator', [
        'module' => 'Planner',
        'name' => 'TripList',
    ])->run();

    expect($second)->toBe(0);
});

it('supports --force overwrite with backup', function (): void {
    $fs = new Filesystem();

    $path = base_path('modules/Planner/Domain/Aggregators/TripListAggregator.php');
    $fs->ensureDirectoryExists(\dirname($path));
    $fs->put($path, '<?php final class Stub {}');

    $exit = $this->artisan('ddd-lite:make:aggregator', [
        'module' => 'Planner',
        'name' => 'TripList',
        '--force' => true,
    ])->run();

    expect($exit)->toBe(0);

    $code = (string)$fs->get($path);
    expect($code)->toContain('final class TripListAggregator');

    $backup = base_path(
        'storage/app/ddd-lite_scaffold/backups/' .
        sha1('modules/Planner/Domain/Aggregators/TripListAggregator.php') .
        '.bak'
    );

    expect($fs->exists($backup))->toBeTrue();
});

it('supports rollback by manifest id', function (): void {
    $fs = new Filesystem();

    $dir = base_path('modules/Planner/Domain/Aggregators');
    $fs->ensureDirectoryExists($dir);

    $relativePath = 'modules/Planner/Domain/Aggregators/TripListForRollbackTestAggregator.php';
    $path = base_path($relativePath);

    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    // 1) Create the aggregator with a unique name so no other test touches this file.
    $exit = $this->artisan('ddd-lite:make:aggregator', [
        'module' => 'Planner',
        'name' => 'TripListForRollbackTest',
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($path))->toBeTrue();

    // 2) Locate the manifest that actually references this path.
    $manifestDir = storage_path('app/ddd-lite_scaffold/manifests');
    $fs->ensureDirectoryExists($manifestDir);

    $files = glob($manifestDir . '/*.json') ?: [];

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
            if (!is_array($action)) {
                continue;
            }
            $pathInAction = $action['path'] ?? $action['target'] ?? null;

            if ($pathInAction === $relativePath) {
                $manifestId = $data['id'] ?? pathinfo($file, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($manifestId)->not->toBeNull();

    // 3) Roll back exactly that manifest and assert the file is gone.
    $exit2 = $this->artisan('ddd-lite:make:aggregator', [
        'module' => 'Planner',
        'name' => 'TripListForRollbackTest',
        '--rollback' => $manifestId,
    ])->run();

    expect($exit2)->toBe(0)
        ->and($fs->exists($path))->toBeFalse();
});
