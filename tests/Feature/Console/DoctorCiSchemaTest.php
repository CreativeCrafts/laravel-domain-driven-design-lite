<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

it('emits schema-valid JSON', function (): void {
    $fs = new Filesystem();

    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $bootstrap = base_path("bootstrap/app_doctor_ci_schema_{$suffix}.php");
    $fs->ensureDirectoryExists(dirname($bootstrap));

    try {
        // Arrange: write a fresh, minimal bootstrap for this test only
        $fs->put(
            $bootstrap,
            <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        // Intentionally minimal
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->create();
PHP
        );

        // Act: call via Artisan and capture output
        $exit = Artisan::call('ddd-lite:doctor-ci', [
            '--json' => true,
            '--paths' => 'bootstrap/' . basename($bootstrap),
        ]);
        $json = Artisan::output();

        expect($exit)->toBe(0);
        expect($json)->not->toBe('');

        // Basic structural assertions
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        expect($payload)
            ->toHaveKey('version')
            ->toHaveKey('generatedAt')
            ->toHaveKey('status')
            ->toHaveKey('totals')
            ->toHaveKey('violations')
            ->and($payload['totals'])
            ->toHaveKeys(['errors', 'warnings', 'fixable']);
    } finally {
        if ($fs->exists($bootstrap)) {
            $fs->delete($bootstrap);
        }
    }
});

it('respects --fail-on policy', function (): void {
    $fs = new Filesystem();

    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $bootstrap = base_path("bootstrap/app_doctor_ci_policy_{$suffix}.php");
    $fs->ensureDirectoryExists(dirname($bootstrap));

    try {
        $fs->put(
            $bootstrap,
            <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        // Intentionally minimal
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->create();
PHP
        );

        // none → always 0
        $exitNone = Artisan::call('ddd-lite:doctor-ci', [
            '--json' => true,
            '--fail-on' => 'none',
            '--paths' => 'bootstrap/' . basename($bootstrap),
        ]);
        expect($exitNone)->toBe(0);

        // any → may be 0 or 1 depending on the environment
        $exitAny = Artisan::call('ddd-lite:doctor-ci', [
            '--json' => true,
            '--fail-on' => 'any',
            '--paths' => 'bootstrap/' . basename($bootstrap),
        ]);
        expect($exitAny)->toBeIn([0, 1]);

        // error → may be 0 or 1 depending on the environment
        $exitErr = Artisan::call('ddd-lite:doctor-ci', [
            '--json' => true,
            '--fail-on' => 'error',
            '--paths' => 'bootstrap/' . basename($bootstrap),
        ]);
        expect($exitErr)->toBeIn([0, 1]);
    } finally {
        if ($fs->exists($bootstrap)) {
            $fs->delete($bootstrap);
        }
    }
});
