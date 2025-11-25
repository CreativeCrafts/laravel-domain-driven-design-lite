<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $fs = new Filesystem();
    $bootstrap = base_path('bootstrap/app.php');
    $fs->ensureDirectoryExists(dirname($bootstrap));
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

it('performs a dry-run without creating any files', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerDryRun';

    // Ensure Modules\\ PSR-4 mapping exists so the guard does not try to write composer.json
    $composerPath = base_path('composer.json');
    $raw = $fs->get($composerPath);
    /** @var array<string,mixed> $composer */
    $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $composer['autoload'] ??= [];
    $composer['autoload']['psr-4'] ??= [];
    $composer['autoload']['psr-4']['Modules\\\\'] = 'modules/';
    $fs->put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    $moduleRoot = base_path("modules/{$module}");
    if ($fs->isDirectory($moduleRoot)) {
        $fs->deleteDirectory($moduleRoot);
    }

    $this->artisan('ddd-lite:module', [
        'name' => $module,
        '--dry-run' => true,
    ])->expectsConfirmation("Proceed to scaffold modules/{$module}?", 'yes')
      ->assertExitCode(0);

    // No directories should exist in dry-run
    expect($fs->isDirectory($moduleRoot))->toBeFalse();
});

it('scaffolds a module and registers providers', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerHappy';

    // Remove any leftovers
    $fs->deleteDirectory(base_path("modules/{$module}"));

    // Ensure composer has no Modules mapping to exercise the write path
    $composerPath = base_path('composer.json');
    $raw = $fs->get($composerPath);
    /** @var array<string,mixed> $composer */
    $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $composer['autoload'] ??= [];
    $composer['autoload']['psr-4'] ??= [];
    unset($composer['autoload']['psr-4']['Modules\\\\']);
    $fs->put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    $this->artisan('ddd-lite:module', [
        'name' => $module,
    ])->expectsConfirmation("Proceed to scaffold modules/{$module}?", 'yes')
      ->assertExitCode(0);

    // Directories created
    expect($fs->isDirectory(base_path("modules/{$module}/App/Providers")))->toBeTrue();
    expect($fs->exists(base_path("modules/{$module}/App/Providers/{$module}ServiceProvider.php")))->toBeTrue();
    expect($fs->exists(base_path("modules/{$module}/Routes/web.php")))->toBeTrue();
    expect($fs->exists(base_path("modules/{$module}/Routes/api.php")))->toBeTrue();

    // Composer mapping ensured (string-based check to avoid array-shape pitfalls)
    $composerJson = (string)$fs->get($composerPath);
    expect($composerJson)->toContain('"Modules\\\\": "modules/"');
});

it('fails without --force when provider already exists, and succeeds with --force', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerForce';

    $root = base_path("modules/{$module}");
    $fs->deleteDirectory($root);
    $fs->ensureDirectoryExists(base_path("modules/{$module}/App/Providers"));

    // Pre-create a provider to force a collision
    $providerPath = base_path("modules/{$module}/App/Providers/{$module}ServiceProvider.php");
    $fs->put($providerPath, "<?php // original\n");

    // First run should fail and rollback due to existing file without --force
    $this->artisan('ddd-lite:module', [
        'name' => $module,
    ])->expectsConfirmation("Proceed to scaffold modules/{$module}?", 'yes')
      ->assertExitCode(1);

    // The pre-created file should still exist with original contents
    expect($fs->exists($providerPath))->toBeTrue();
    expect($fs->get($providerPath))->toContain('original');

    // Now run with --force to overwrite
    $this->artisan('ddd-lite:module', [
        'name' => $module,
        '--force' => true,
    ])->expectsConfirmation("Proceed to scaffold modules/{$module}?", 'yes')
      ->assertExitCode(0);

    // Contents should have changed (no longer only 'original')
    expect($fs->get($providerPath))->not->toContain('original');
});

it('renames lowercase folder to PascalCase when --fix-psr4 is set', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerCase';

    // Start with a lowercase folder to trigger normalization
    $lower = base_path('modules/plannercase');
    $proper = base_path('modules/PlannerCase');
    $fs->deleteDirectory($lower);
    $fs->deleteDirectory($proper);
    $fs->ensureDirectoryExists($lower);

    $this->artisan('ddd-lite:module', [
        'name' => $module,
        '--fix-psr4' => true,
    ])->expectsConfirmation("Proceed to scaffold modules/{$module}?", 'yes')
      ->assertExitCode(0);

    expect($fs->isDirectory($proper))->toBeTrue();
    // On case-insensitive filesystems, the lowercased path may still resolve; assert provider exists under proper path.
    expect($fs->exists(base_path("modules/{$module}/App/Providers/{$module}ServiceProvider.php")))->toBeTrue();
});
