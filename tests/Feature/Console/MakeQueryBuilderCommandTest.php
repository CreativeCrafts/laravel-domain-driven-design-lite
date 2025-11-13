<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

function qbUniqueSuffix(): string {
    return strtoupper(substr(md5(uniqid('', true)), 0, 8));
}

it('creates a QueryBuilder class', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerQB' . qbUniqueSuffix();
    $classBase = 'Trip';
    $class = 'TripQueryBuilder';
    $dir = base_path("modules/{$module}/Domain/Builders");
    $path = base_path("modules/{$module}/Domain/Builders/{$class}.php");

    if ($fs->exists(base_path("modules/{$module}"))) {
        $fs->deleteDirectory(base_path("modules/{$module}"));
    }
    $fs->ensureDirectoryExists($dir);

    $exit = $this->artisan('ddd-lite:make:query-builder', [
        'module' => $module,
        'name' => $classBase,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($path))->toBeTrue();

    $code = (string)$fs->get($path);
    expect($code)->toContain("namespace Modules\\{$module}\\Domain\\Builders;")
        ->and($code)->toContain("final class {$class} extends Builder");
});

it('honors dry-run (no writes)', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerQB' . qbUniqueSuffix();
    $classBase = 'Invoice';
    $class = 'InvoiceQueryBuilder';
    $dir = base_path("modules/{$module}/Domain/Builders");
    $path = base_path("modules/{$module}/Domain/Builders/{$class}.php");

    if ($fs->exists(base_path("modules/{$module}"))) {
        $fs->deleteDirectory(base_path("modules/{$module}"));
    }
    $fs->ensureDirectoryExists($dir);

    $exit = $this->artisan('ddd-lite:make:query-builder', [
        'module' => $module,
        'name' => $classBase,
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($path))->toBeFalse();
});

it('is idempotent when unchanged', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerQB' . qbUniqueSuffix();
    $classBase = 'Order';
    $class = 'OrderQueryBuilder';
    $dir = base_path("modules/{$module}/Domain/Builders");
    $path = base_path("modules/{$module}/Domain/Builders/{$class}.php");

    if ($fs->exists(base_path("modules/{$module}"))) {
        $fs->deleteDirectory(base_path("modules/{$module}"));
    }
    $fs->ensureDirectoryExists($dir);
    $fs->put(
        $path,
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace Modules\\{$module}\\Domain\\Builders;\n\nuse Illuminate\\Database\\Eloquent\\Builder;\n\nfinal class {$class} extends Builder\n{\n}\n"
    );

    $exit = $this->artisan('ddd-lite:make:query-builder', [
        'module' => $module,
        'name' => $classBase,
    ])->run();

    expect($exit)->toBe(0);
});

it('overwrites with --force and creates a backup', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerQB' . qbUniqueSuffix();
    $classBase = 'Customer';
    $class = 'CustomerQueryBuilder';
    $dir = base_path("modules/{$module}/Domain/Builders");
    $relative = "modules/{$module}/Domain/Builders/{$class}.php";
    $path = base_path($relative);

    if ($fs->exists(base_path("modules/{$module}"))) {
        $fs->deleteDirectory(base_path("modules/{$module}"));
    }
    $fs->ensureDirectoryExists($dir);
    $fs->put($path, "<?php\n// old\n");

    $exit = $this->artisan('ddd-lite:make:query-builder', [
        'module' => $module,
        'name' => $classBase,
        '--force' => true,
    ])->run();

    expect($exit)->toBe(0);

    $content = (string)$fs->get($path);
    expect($content)->toContain("final class {$class} extends Builder");

    $manifests = \glob(storage_path('app/ddd-lite_scaffold/manifests/*.json')) ?: [];
    expect($manifests)->not()->toBeEmpty();

    /** @var array<int,string> $manifests */
    $foundUpdate = null;
    foreach ($manifests as $m) {
        if (!is_file($m)) {
            continue;
        }

        $raw = @file_get_contents($m);
        if ($raw === false || $raw === '') {
            continue;
        }

        /** @var array<string,mixed>|null $json */
        $json = \json_decode($raw, true);
        if (!\is_array($json)) {
            continue;
        }
        if (!isset($json['actions']) || !\is_array($json['actions'])) {
            continue;
        }

        /** @var array<int,array<string,string|null>> $actions */
        $actions = $json['actions'];
        $candidate = \collect($actions)->first(
            fn($a) => ($a['type'] ?? null) === 'update'
                && ($a['path'] ?? null) === $relative
        );

        if ($candidate !== null) {
            $foundUpdate = $candidate;
            break;
        }
    }

    expect($foundUpdate)->not->toBeNull();
    $backup = (string)(($foundUpdate['backup'] ?? '') ?: '');
    expect($backup)->not->toBe('')
        ->and($fs->exists(base_path($backup)))->toBeTrue();
});

it('supports rollback by manifest id', function (): void {
    $fs = new Illuminate\Filesystem\Filesystem();

    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $module = 'PlannerQB' . $suffix;

    // 1) Generate a query builder
    $exit = $this->artisan('ddd-lite:make:query-builder', [
        'module' => $module,
        'name' => 'Trips',
    ])->run();

    expect($exit)->toBe(0);

    // 2) Compute the relative path of the generated file (Builders, not Queries)
    $abs = base_path("modules/{$module}/Domain/Builders/TripsQueryBuilder.php");
    expect($fs->exists($abs))->toBeTrue();
    $rel = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $abs), DIRECTORY_SEPARATOR);

    // 3) Locate the manifest that references this path
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
            if ($p === $rel) {
                $manifestId = $data['id'] ?? pathinfo($mf, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($manifestId)->not->toBeNull();

    // 4) Rollback using the found ID
    $rollbackExit = $this->artisan('ddd-lite:make:query-builder', [
        'module' => $module,
        'name' => 'Trips',
        '--rollback' => $manifestId,
    ])->run();

    expect($rollbackExit)->toBe(0)
        ->and($fs->exists($abs))->toBeFalse();
});
