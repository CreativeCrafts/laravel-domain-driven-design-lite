<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('creates a RouteServiceProvider and registers it', function (): void {
    $fs = new Filesystem();
    // Use a unique module name to avoid clashes in parallel runs.
    $module = 'PlannerProviderDemo' . strtoupper(substr(md5(uniqid('', true)), 0, 8));

    $dir = base_path("modules/{$module}/App/Providers");
    $fs->ensureDirectoryExists($dir);

    $msp = "{$dir}/{$module}ServiceProvider.php";
    if (!$fs->exists($msp)) {
        $fs->put(
            $msp,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Modules\\{$module}\\App\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nfinal class {$module}ServiceProvider extends ServiceProvider\n{\n    public function register(): void\n    {\n    }\n\n    public function boot(): void\n    {\n    }\n}\n",
        );
    }

    $path = "{$dir}/RouteServiceProvider.php";
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $exit = $this->artisan('ddd-lite:make:provider', [
        'module' => $module,
        '--type' => 'route',
        '--register' => true,
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0);
    expect($fs->exists($path))->toBeFalse();

    $exit2 = $this->artisan('ddd-lite:make:provider', [
        'module' => $module,
        '--type' => 'route',
        '--register' => true,
    ])->run();

    expect($exit2)->toBe(0);
    expect($fs->exists($path))->toBeTrue();

    $code = (string)$fs->get($path);
    expect($code)->toContain('final class RouteServiceProvider');

    $mspCode = (string)$fs->get($msp);
    expect($mspCode)->toContain("use Modules\\{$module}\\App\\Providers\\RouteServiceProvider;")
        ->and($mspCode)->toContain('$this->app->register(RouteServiceProvider::class);');
});

it('creates an EventServiceProvider and registers it', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerProviderDemo' . strtoupper(substr(md5(uniqid('', true)), 0, 8));

    $dir = base_path("modules/{$module}/App/Providers");
    $fs->ensureDirectoryExists($dir);

    $msp = "{$dir}/{$module}ServiceProvider.php";
    if (!$fs->exists($msp)) {
        $fs->put(
            $msp,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Modules\\{$module}\\App\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nfinal class {$module}ServiceProvider extends ServiceProvider\n{\n    public function register(): void\n    {\n    }\n\n    public function boot(): void\n    {\n    }\n}\n",
        );
    }

    $path = "{$dir}/EventServiceProvider.php";
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $exit = $this->artisan('ddd-lite:make:provider', [
        'module' => $module,
        '--type' => 'event',
        '--register' => true,
    ])->run();

    expect($exit)->toBe(0);
    expect($fs->exists($path))->toBeTrue();

    $code = (string)$fs->get($path);
    expect($code)->toContain('final class EventServiceProvider');

    $mspCode = (string)$fs->get($msp);
    expect($mspCode)->toContain("use Modules\\{$module}\\App\\Providers\\EventServiceProvider;")
        ->and($mspCode)->toContain('$this->app->register(EventServiceProvider::class);');
});

it('supports rollback for provider file and registration', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerProviderDemo' . strtoupper(substr(md5(uniqid('', true)), 0, 8));

    $dir = base_path("modules/{$module}/App/Providers");
    $fs->ensureDirectoryExists($dir);

    $msp = "{$dir}/{$module}ServiceProvider.php";
    $fs->put(
        $msp,
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace Modules\\{$module}\\App\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nfinal class {$module}ServiceProvider extends ServiceProvider\n{\n    public function register(): void\n    {\n    }\n\n    public function boot(): void\n    {\n    }\n}\n",
    );

    // Ensure we start from a clean state so this test is independent of others.
    $path = "{$dir}/RouteServiceProvider.php";
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $this->artisan('ddd-lite:make:provider', [
        'module' => $module,
        '--type' => 'route',
        '--register' => true,
    ])->run();

    // Instead of relying on before/after diffs (which are racy in parallel),
    // discover the manifest by inspecting actions for our specific provider path.
    $manifestFiles = glob(storage_path('app/ddd-lite_scaffold/manifests/*.json')) ?: [];

    $relProviderPath = ltrim(
        str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path),
        DIRECTORY_SEPARATOR,
    );

    $manifestId = null;

    foreach ($manifestFiles as $file) {
        // In parallel runs, a manifest may disappear between glob() and read; guard accordingly.
        if (!is_file($file)) {
            continue;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            continue;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }

        $actions = $data['actions'] ?? [];
        if (!is_array($actions)) {
            continue;
        }

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = $action['type'] ?? null;
            $actionPath = $action['path'] ?? null;

            if (
                ($type === 'create' || $type === 'update')
                && $actionPath === $relProviderPath
            ) {
                $manifestId = $data['id'] ?? pathinfo($file, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($manifestId)->not->toBeNull();

    $this->artisan('ddd-lite:make:provider', [
        '--rollback' => $manifestId,
    ])->run();

    // Provider file should be gone after rollback.
    expect($fs->exists($path))->toBeFalse();

    // And the module service provider should no longer reference it.
    $mspCode = (string)$fs->get($msp);
    expect($mspCode)->not->toContain("use Modules\\{$module}\\App\\Providers\\RouteServiceProvider;")
        ->and($mspCode)->not->toContain('$this->app->register(RouteServiceProvider::class);');
});