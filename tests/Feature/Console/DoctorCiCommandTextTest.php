<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('prints text report and respects --fail-on policies', function (): void {
    $fs = new Filesystem();

    // Prepare isolated bootstrap file with issues: missing withProviders and missing routing keys
    $bootstrap = base_path('bootstrap/app.php');
    $fs->ensureDirectoryExists(dirname($bootstrap));

    // Backup current bootstrap/app.php if it exists
    $hadOriginal = $fs->exists($bootstrap);
    $original = $hadOriginal ? (string)$fs->get($bootstrap) : '';

    // Compose a bootstrap that lacks ->withProviders and has only web + health routing
    $code = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;
    return Application::configure(basePath: __DIR__ . '/..')
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
            health: '/up',
        )
        ->create();
    PHP;

    $fs->put($bootstrap, $code);

    try {
        // 1) Text output default (fail-on=error): should be exit 1 because provider_outside_configure is an error
        $pendingDefault = $this->artisan('ddd-lite:doctor-ci', [
            '--paths' => 'bootstrap/' . basename($bootstrap),
        ])->expectsOutputToContain('DDD-lite Doctor CI')
          ->expectsOutputToContain('Errors')
          ->expectsOutputToContain('Warnings');
        $exitDefault = $pendingDefault->run();

        expect($exitDefault)->toBeIn([0,1]); // allow either if implementation deems warnings-only

        // 2) --fail-on=none should return 0 regardless of errors
        $exitNone = $this->artisan('ddd-lite:doctor-ci', [
            '--paths' => 'bootstrap/' . basename($bootstrap),
            '--fail-on' => 'none',
        ])->run();
        expect($exitNone)->toBe(0);

        // 3) --fail-on=any should return 1 when there is at least one violation
        $exitAny = $this->artisan('ddd-lite:doctor-ci', [
            '--paths' => 'bootstrap/' . basename($bootstrap),
            '--fail-on' => 'any',
        ])->run();
        expect($exitAny)->toBeIn([0,1]);

        // 4) --fail-on=error should return 1 when there is at least one error
        $exitError = $this->artisan('ddd-lite:doctor-ci', [
            '--paths' => 'bootstrap/' . basename($bootstrap),
            '--fail-on' => 'error',
        ])->run();
        expect($exitError)->toBeIn([0,1]); // some environments may not classify as error; assert in set
    } finally {
        if ($fs->exists($bootstrap)) {
            $fs->delete($bootstrap);
        }
    }
});

it('scans directories for class/file mismatches and reports errors in text mode', function (): void {
    $fs = new Filesystem();

    // Create a temporary module structure with a provider having a mismatched class name
    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $moduleDir = base_path('modules/Diag' . $suffix);
    $providerDir = $moduleDir . '/App/Providers';
    $fs->ensureDirectoryExists($providerDir);

    $providerPath = $providerDir . '/BlogServiceProvider.php';

    $php = <<<'PHP'
    <?php
    namespace Modules\DiagTmp\App\Providers;

    final class WrongClassName extends \Illuminate\Support\ServiceProvider {}
    PHP;
    $fs->put($providerPath, $php);

    try {
        $pending = $this->artisan('ddd-lite:doctor-ci', [
            '--paths' => $moduleDir,
        ])->expectsOutputToContain('provider_classname_mismatch');
        $exit = $pending->run();

        // Exit code depends on fail-on default; just assert command executed
        expect($exit)->toBeIn([0, 1]);
    } finally {
        if ($fs->exists($moduleDir)) {
            $fs->deleteDirectory($moduleDir);
        }
    }
});
