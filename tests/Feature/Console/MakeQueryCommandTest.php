<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('creates a Query file (happy path)', function (): void {
    $fs = new Filesystem();

    // Use a module name scoped to this scenario.
    $module = 'PlannerQueryHappy';
    $target = base_path("modules/{$module}/Domain/Queries");

    // Ensure a clean slate for this module.
    $fs->deleteDirectory(base_path("modules/{$module}"));
    $fs->ensureDirectoryExists($target);

    $exit = $this->artisan('ddd-lite:make:query', [
        'module' => $module,
        'name' => 'TripIndexQuery',
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists("{$target}/TripIndexQuery.php"))->toBeFalse();

    $exit2 = $this->artisan('ddd-lite:make:query', [
        'module' => $module,
        'name' => 'TripIndexQuery',
    ])->run();

    expect($exit2)->toBe(0);

    $path = base_path("modules/{$module}/Domain/Queries/TripIndexQuery.php");
    expect($fs->exists($path))->toBeTrue();

    $code = (string)$fs->get($path);
    expect($code)
        ->toContain("namespace Modules\\{$module}\\Domain\\Queries;")
        ->and($code)->toContain('final readonly class TripIndexQuery')
        ->and($code)->toContain('function run(): array');
});

it('is idempotent when regenerated unchanged', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerQueryIdem';
    $dir = base_path("modules/{$module}/Domain/Queries");
    $path = "{$dir}/TripIndexQuery.php";

    // Clean module root for this scenario only.
    $fs->deleteDirectory(base_path("modules/{$module}"));
    $fs->ensureDirectoryExists($dir);

    $exit1 = $this->artisan('ddd-lite:make:query', [
        'module' => $module,
        'name' => 'TripIndexQuery',
    ])->run();

    expect($exit1)->toBe(0)
        ->and($fs->exists($path))->toBeTrue();

    $exit2 = $this->artisan('ddd-lite:make:query', [
        'module' => $module,
        'name' => 'TripIndexQuery',
    ])->run();

    expect($exit2)->toBe(0)
        ->and($fs->exists($path))->toBeTrue();
});

it('refuses overwrite without --force when content differs', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerQueryOverwrite';
    $dir = base_path("modules/{$module}/Domain/Queries");
    $path = "{$dir}/TripIndexQuery.php";

    // Isolate this module; ensure the directory and a diverging file exist.
    $fs->deleteDirectory(base_path("modules/{$module}"));
    $fs->ensureDirectoryExists($dir);
    $fs->put($path, "<?php\n\n// diverge\n");

    expect($fs->exists($path))->toBeTrue();

    $exit = $this->artisan('ddd-lite:make:query', [
        'module' => $module,
        'name' => 'TripIndexQuery',
    ])->run();

    expect($exit)->toBe(1);
});

it('overwrites with --force and creates a backup', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerQueryOverwriteForce';
    $dir = base_path("modules/{$module}/Domain/Queries");
    $path = "{$dir}/TripIndexQuery.php";

    // Clean and prepare the module directory with a diverging file.
    $fs->deleteDirectory(base_path("modules/{$module}"));
    $fs->ensureDirectoryExists($dir);
    $fs->put($path, "<?php\n\n// diverge\n");

    $exit = $this->artisan('ddd-lite:make:query', [
        'module' => $module,
        'name' => 'TripIndexQuery',
        '--force' => true,
    ])->run();

    expect($exit)->toBe(0);

    $backup = storage_path(
        'app/ddd-lite_scaffold/backups/' .
        sha1("modules/{$module}/Domain/Queries/TripIndexQuery.php") .
        '.bak',
    );

    expect($fs->exists($backup))->toBeTrue();

    $code = (string)$fs->get($path);
    expect($code)->toContain('final readonly class TripIndexQuery');
});

it('supports rollback by manifest id', function (): void {
    $fs = new Filesystem();

    $module = 'BillingQueryRollback';
    $relCreated = "modules/{$module}/Domain/Queries/InvoiceListQuery.php";
    $absCreated = base_path($relCreated);

    // Ensure a clean module root, then recreate the module + Queries dir.
    $moduleRoot = base_path("modules/{$module}");
    $fs->deleteDirectory($moduleRoot);
    $fs->ensureDirectoryExists($moduleRoot . '/Domain/Queries');

    // Create the Query (this should record a manifest with a "create" for $relCreated).
    $exit = $this->artisan('ddd-lite:make:query', [
        'module' => $module,
        'name' => 'InvoiceListQuery',
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($absCreated))->toBeTrue();

    // Find the manifest that actually created our file (do not assume "last manifest").
    $manifestsDir = storage_path('app/ddd-lite_scaffold/manifests');
    $candidates = glob($manifestsDir . '/*.json') ?: [];
    expect(count($candidates))->toBeGreaterThan(0);

    $targetId = null;

    foreach ($candidates as $manifestPath) {
        // In parallel mode, a manifest file may be deleted between discovery and read.
        if (!is_file($manifestPath)) {
            continue;
        }
        $raw = @file_get_contents($manifestPath);
        if ($raw === false || $raw === '') {
            continue;
        }
        /** @var array{id?:string,actions?:array<int, array<string, string>>}|null $json */
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            continue;
        }
        $id = (string)($json['id'] ?? '');
        $actions = (array)($json['actions'] ?? []);

        foreach ($actions as $op) {
            if (($op['type'] ?? '') === 'create' && ($op['path'] ?? '') === $relCreated) {
                $targetId = $id ?: pathinfo($manifestPath, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($targetId)->not->toBe('');

    $exit2 = $this->artisan('ddd-lite:make:query', [
        // These args are ignored during rollback, but we keep them to mirror real usage.
        'module' => 'IgnoredModule',
        'name' => 'IgnoredName',
        '--rollback' => $targetId,
    ])->run();

    expect($exit2)->toBe(0)
        ->and($fs->exists($absCreated))->toBeFalse();
});
