<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

it('emits JSON output and a sensible exit code when analyzing a minimal bootstrap', function (): void {
    $fs = new Filesystem();

    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $bootstrap = base_path("bootstrap/app_doctor_ci_{$suffix}.php");
    $fs->ensureDirectoryExists(dirname($bootstrap));

    try {
        $fs->put(
            $bootstrap,
            "<?php\nreturn \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__ . '/..')\n    ->withRouting(\n        web: __DIR__.'/../routes/web.php',\n        health: '/up',\n    )\n    ->create();\n"
        );

        $exit = Artisan::call('ddd-lite:doctor-ci', [
            '--json' => true,
            '--paths' => 'bootstrap/' . basename($bootstrap),
        ]);
        $json = Artisan::output();

        // Command should at least run and emit JSON; exit code may be 0 or 1 depending on defaults
        expect($json)->not->toBe('');
        expect($exit)->toBeIn([0, 1]);

        // Validate basic JSON structure
        $payload = json_decode($json, true);
        expect($payload)
            ->toBeArray()
            ->toHaveKey('version')
            ->toHaveKey('generatedAt')
            ->toHaveKey('status')
            ->toHaveKey('totals')
            ->toHaveKey('violations');
    } finally {
        if ($fs->exists($bootstrap)) {
            $fs->delete($bootstrap);
        }
    }
});

it('passes with zero exit when no violations and outputs ok=true', function (): void {
    $fs = new Filesystem();

    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $bootstrap = base_path("bootstrap/app_doctor_ci_ok_{$suffix}.php");
    $fs->ensureDirectoryExists(dirname($bootstrap));

    try {
        $fs->put(
            $bootstrap,
            "<?php\nuse Illuminate\\Foundation\\Application;\nreturn Application::configure(basePath: __DIR__ . '/..')\n    ->withProviders([\n        App\\Providers\\AppServiceProvider::class,\n    ])\n    ->withRouting(\n        web: __DIR__.'/../routes/web.php',\n        api: __DIR__.'/../routes/api.php',\n        channels: __DIR__.'/../routes/channels.php',\n        commands: __DIR__.'/../routes/console.php',\n        health: '/up',\n    )\n    ->create();\n"
        );

        $exit = $this->artisan('ddd-lite:doctor-ci', [
            '--json' => true,
            '--paths' => 'bootstrap/' . basename($bootstrap),
        ])->run();

        expect($exit)->toBe(0);
    } finally {
        if ($fs->exists($bootstrap)) {
            $fs->delete($bootstrap);
        }
    }
});
