<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

it('creates an Aggregate Root', function (): void {
    $fs = new Filesystem();

    $module = 'Planner';
    $fs->ensureDirectoryExists(base_path("modules/{$module}/Domain/Aggregates"));

    $targetDir = base_path("modules/{$module}/Domain/Aggregates/Trip");
    $target = "{$targetDir}/Trip.php";

    if ($fs->exists($target)) {
        $fs->delete($target);
    }
    if ($fs->isDirectory($targetDir)) {
        $fs->deleteDirectory($targetDir);
    }

    $exit = $this->artisan('ddd-lite:make:aggregate-root', [
        'module' => $module,
        'name' => 'Trip',
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($target))->toBeTrue();

    $code = (string)$fs->get($target);

    expect($code)
        ->toContain('namespace Modules\\Planner\\Domain\\Aggregates\\Trip;')
        ->toContain('final class Trip')
        ->and($code)->not->toContain('extends AggregateRoot')
        ->toContain('protected function ensureInvariants(): void');
});

it('supports dry-run without writes', function (): void {
    $fs = new Filesystem();

    $module = 'Planner';
    $fs->ensureDirectoryExists(base_path("modules/{$module}/Domain/Aggregates"));

    $target = base_path("modules/{$module}/Domain/Aggregates/Order/Order.php");
    if ($fs->exists($target)) {
        $fs->delete($target);
    }

    $exit = $this->artisan('ddd-lite:make:aggregate-root', [
        'module' => $module,
        'name' => 'Order',
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($target))->toBeFalse();
});

it('supports force overwrite and creates backups', function (): void {
    $fs = new Filesystem();

    $module = 'Planner';
    $name = 'Booking';
    $fs->ensureDirectoryExists(base_path("modules/{$module}/Domain/Aggregates"));

    $targetDir = base_path("modules/{$module}/Domain/Aggregates/{$name}");
    $target = "{$targetDir}/{$name}.php";

    // Seed a different file so we can detect overwrite + backup
    $fs->ensureDirectoryExists($targetDir);
    $fs->put($target, "<?php\n// original\n");

    // First overwrite with generated aggregate using --force
    $exit = $this->artisan('ddd-lite:make:aggregate-root', [
        'module' => $module,
        'name' => $name,
        '--force' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($target))->toBeTrue();

    $code = (string)$fs->get($target);
    expect($code)
        ->toContain('final class Booking')
        ->and($code)->not->toContain('extends AggregateRoot');

    // Backup directory must exist (convention shared across make commands)
    $backupDir = base_path('storage/app/ddd-lite_scaffold/backups');
    expect($fs->exists($backupDir))->toBeTrue();
});

it('is idempotent when no changes are needed', function (): void {
    $fs = new Filesystem();

    $module = 'Planner';
    $name = 'Itinerary';
    $fs->ensureDirectoryExists(base_path("modules/{$module}/Domain/Aggregates"));

    $targetDir = base_path("modules/{$module}/Domain/Aggregates/{$name}");
    $target = "{$targetDir}/{$name}.php";

    if ($fs->exists($target)) {
        $fs->delete($target);
    }
    if ($fs->isDirectory($targetDir)) {
        $fs->deleteDirectory($targetDir);
    }

    // First create
    $exit1 = $this->artisan('ddd-lite:make:aggregate-root', [
        'module' => $module,
        'name' => $name,
    ])->run();

    expect($exit1)->toBe(0);
    $original = $fs->exists($target) ? (string)$fs->get($target) : '';

    // Second run without --force, unchanged
    $exit2 = $this->artisan('ddd-lite:make:aggregate-root', [
        'module' => $module,
        'name' => $name,
    ])->run();

    expect($exit2)->toBe(0);

    $after = $fs->exists($target) ? (string)$fs->get($target) : '';
    expect($after)->toBe($original);
});

it('supports rollback by manifest id', function (): void {
    $fs = new Filesystem();

    $module = 'Planner';
    $name = 'EmailThread';
    $fs->ensureDirectoryExists(base_path("modules/{$module}/Domain/Aggregates"));

    $targetDir = base_path("modules/{$module}/Domain/Aggregates/{$name}");
    $target = "{$targetDir}/{$name}.php";

    if ($fs->exists($target)) {
        $fs->delete($target);
    }
    if ($fs->isDirectory($targetDir)) {
        $fs->deleteDirectory($targetDir);
    }

    // Create aggregate and capture manifest id from output
    $exitCreate = Artisan::call('ddd-lite:make:aggregate-root', [
        'module' => $module,
        'name' => $name,
    ]);

    expect($exitCreate)->toBe(0);

    $output = Artisan::output();
    preg_match('/Manifest:\s+([a-f0-9\-]+)/i', $output, $m);
    expect($m)->toHaveCount(2);
    $manifestId = $m[1];

    // Ensure file exists
    expect($fs->exists($target))->toBeTrue();

    // Rollback using captured manifest id
    $exitRollback = Artisan::call('ddd-lite:make:aggregate-root', [
        'module' => $module, // ignored on rollback
        'name' => $name,     // ignored on rollback
        '--rollback' => $manifestId,
    ]);

    expect($exitRollback)->toBe(0)
        ->and($fs->exists($target))->toBeFalse();
});
