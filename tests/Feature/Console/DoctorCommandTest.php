<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

function backupComposerAndBootstrap(): array
{
    $fs = new Filesystem();
    $composer = base_path('composer.json');
    $bootstrap = base_path('bootstrap/app.php');

    $fs->ensureDirectoryExists(dirname($bootstrap));

    return [
        'composer_had' => $fs->exists($composer),
        'composer_raw' => $fs->exists($composer) ? (string)$fs->get($composer) : '',
        'bootstrap_had' => $fs->exists($bootstrap),
        'bootstrap_raw' => $fs->exists($bootstrap) ? (string)$fs->get($bootstrap) : '',
    ];
}

function restoreComposerAndBootstrap(array $b): void
{
    $fs = new Filesystem();
    $composer = base_path('composer.json');
    $bootstrap = base_path('bootstrap/app.php');

    if (($b['composer_had'] ?? false) && is_string($b['composer_raw'] ?? null)) {
        $fs->put($composer, (string)$b['composer_raw']);
    } else {
        // Ensure a minimal valid composer.json exists for other tests
        if (!$fs->exists($composer)) {
            $fs->put($composer, json_encode(['name' => 'example/app'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    if (($b['bootstrap_had'] ?? false) && is_string($b['bootstrap_raw'] ?? null)) {
        $fs->put($bootstrap, (string)$b['bootstrap_raw']);
    } else {
        if ($fs->exists($bootstrap)) {
            $fs->delete($bootstrap);
        }
    }
}

it('emits JSON with composer/missing-routing/provider issues in dry-run', function (): void {
    $fs = new Filesystem();
    $backup = backupComposerAndBootstrap();

    $composer = base_path('composer.json');
    $bootstrap = base_path('bootstrap/app.php');

    try {
        // Composer without Modules mapping
        $fs->put($composer, json_encode([
            'name' => 'example/app',
            'autoload' => [ 'psr-4' => [ 'App\\' => 'app/' ] ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Bootstrap with withRouting missing api/channels and no withProviders
        $fs->put($bootstrap, <<<'PHP'
<?php
use Illuminate\Foundation\Application;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    ->create();
PHP);

        // Create a dummy module with a mismatched provider class to also populate modules issues
        $module = 'DocDemo' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $providerDir = base_path("modules/{$module}/App/Providers");
        $fs->ensureDirectoryExists($providerDir);
        $fs->put($providerDir . '/'.$module.'ServiceProvider.php', <<<'PHP'
<?php
namespace Modules\Tmp\App\Providers;
final class WrongName extends \Illuminate\Support\ServiceProvider {}
PHP);

        $exit = Artisan::call('ddd-lite:doctor', [
            '--json' => true,
            '--dry-run' => true,
            '--module' => $module,
            '--prefer' => 'file',
        ]);

        expect($exit)->toBe(0);

        $raw = Artisan::output();
        // Extract the last JSON object from mixed output
        $payload = null;
        if (preg_match_all('/\{[\s\S]*\}\s*$/m', $raw, $mm) && !empty($mm[0])) {
            $last = end($mm[0]);
            $payload = json_decode((string)$last, true);
        } else {
            $payload = json_decode($raw, true);
        }

        expect($payload)->toBeArray()
            ->and($payload['composer_psr4']['has_modules_mapping'] ?? null)->toBeFalse()
            ->and($payload['routing']['missing'] ?? [])->toContain('api')
            ->and($payload['routing']['missing'] ?? [])->toContain('channels');

        // Find our module in the report (position-independent)
        $names = array_map(static fn ($m) => $m['name'] ?? null, (array)($payload['modules'] ?? []));
        expect($names)->toContain($module);
        $modEntry = null;
        foreach (($payload['modules'] ?? []) as $m) {
            if (($m['name'] ?? '') === $module) {
            $modEntry = $m;
            break;
            }
        }
        expect($modEntry['status'] ?? null)->toBe('error');
    } finally {
        restoreComposerAndBootstrap($backup);
        // Clean module
        $modulesRoot = base_path('modules');
        foreach ($fs->directories($modulesRoot) as $dir) {
            if (str_contains($dir, 'DocDemo')) {
            $fs->deleteDirectory($dir);
            }
        }
    }
});

it('reports mapping presence and would ensure routing keys in dry-run with --fix', function (): void {
    $fs = new Filesystem();
    $backup = backupComposerAndBootstrap();

    $composer = base_path('composer.json');
    $bootstrap = base_path('bootstrap/app.php');

    try {
        // Composer WITH Modules mapping
        $fs->put($composer, json_encode([
            'name' => 'example/app',
            'autoload' => [ 'psr-4' => [ 'App\\' => 'app/', 'Modules\\' => 'modules/' ] ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Bootstrap missing api/channels
        $fs->put($bootstrap, <<<'PHP'
<?php
use Illuminate\Foundation\Application;
return Application::configure(basePath: __DIR__ . '/..')
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    ->create();
PHP);

        // Expect text output lines
        $pending = $this->artisan('ddd-lite:doctor', [
            '--dry-run' => true,
            '--fix' => true,
        ])->expectsOutputToContain('[doctor] composer.json PSR-4 mapping for "Modules\\\\" is present.')
          ->expectsOutputToContain('[doctor] would ensure withRouting keys:');

        $exit = $pending->run();
        expect($exit)->toBe(0);
    } finally {
        restoreComposerAndBootstrap($backup);
    }
});
