<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $fs = new Filesystem();

    $bootstrap = base_path('bootstrap/app.php');

    $fs->ensureDirectoryExists(dirname($bootstrap));

    // Always reset bootstrap/app.php to a known-good template to avoid cross-test interference
    $fs->put(
        $bootstrap,
        <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
PHP
    );
});

it('dry-run prints a plan and performs no writes', function (): void {
    $fs = new Filesystem();

    $bootstrap = base_path('bootstrap/app.php');
    $composer = base_path('composer.json');

    // Unique module to avoid cross-test collisions
    $module = 'ConvertDryRunDemo' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $moduleRoot = base_path("modules/{$module}");
    $provider = base_path("modules/{$module}/App/Providers/{$module}ServiceProvider.php");

    if ($fs->exists($moduleRoot)) {
        $fs->deleteDirectory($moduleRoot);
    }

    expect($fs->exists($bootstrap))->toBeTrue()
        ->and($fs->exists($composer))->toBeTrue();

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => $module,
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($moduleRoot))->toBeFalse()
        ->and($fs->exists($provider))->toBeFalse();
});

it(
    'converts app by creating module skeleton, provider, and wiring bootstrap + composer',
    function (): void {
        $fs = new Filesystem();

        $module = 'Planner' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $moduleRoot = base_path("modules/{$module}");
        $provider = base_path("modules/{$module}/App/Providers/{$module}ServiceProvider.php");
        $bootstrap = base_path('bootstrap/app.php');
        $composer = base_path('composer.json');

        if ($fs->exists($moduleRoot)) {
            $fs->deleteDirectory($moduleRoot);
        }

        $originalComposer = (string)$fs->get($composer);
        $fs->put($composer, $originalComposer);

        $exit = $this->artisan('ddd-lite:convert', [
            'module' => $module,
        ])->run();

        expect($exit)->toBe(0)
            ->and($fs->exists($moduleRoot))->toBeTrue()
            ->and($fs->exists($provider))->toBeTrue();

        $boot = (string)$fs->get($bootstrap);
        expect($boot)->toContain('Modules\\' . $module . '\\App\\Providers\\' . $module . 'ServiceProvider::class');

        $composerJson = (string)$fs->get($composer);
        expect($composerJson)->toContain('"Modules\\\\": "modules/"');
    }
);

it('supports rollback by manifest id', function (): void {
    $fs = new Filesystem();

    $module = 'RollbackDemo' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $moduleRoot = base_path("modules/{$module}");
    $provider = base_path("modules/{$module}/App/Providers/{$module}ServiceProvider.php");
    $lastIdPath = storage_path('app/ddd-lite_scaffold/last_manifest_id.txt');

    if ($fs->exists($moduleRoot)) {
        $fs->deleteDirectory($moduleRoot);
    }
    if ($fs->exists($lastIdPath)) {
        $fs->delete($lastIdPath);
    }

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => $module,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($moduleRoot))->toBeTrue()
        ->and($fs->exists($provider))->toBeTrue()
        ->and($fs->exists($lastIdPath))->toBeTrue();

    $id = trim((string)$fs->get($lastIdPath));
    expect($id)->not->toBe('');

    $exit2 = $this->artisan('ddd-lite:convert', [
        'module' => $module,
        '--rollback' => $id,
    ])->run();

    expect($exit2)->toBe(0)
        ->and($fs->exists($provider))->toBeFalse();

    if ($fs->exists($moduleRoot)) {
        $phpFiles = collect($fs->allFiles($moduleRoot))
            ->filter(function ($file): bool {
                $path = (string)$file;
                return pathinfo($path, PATHINFO_EXTENSION) === 'php';
            });

        expect($phpFiles)->toHaveCount(0);
    }
});
