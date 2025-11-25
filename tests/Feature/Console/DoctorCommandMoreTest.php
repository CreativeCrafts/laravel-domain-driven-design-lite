<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

function backupForDoctorMore(): array
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

function restoreForDoctorMore(array $b): void
{
    $fs = new Filesystem();
    $composer = base_path('composer.json');
    $bootstrap = base_path('bootstrap/app.php');

    if (($b['composer_had'] ?? false) && is_string($b['composer_raw'] ?? null)) {
        $fs->put($composer, (string)$b['composer_raw']);
    } else {
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

    // Clean modules dir created by tests
    $modulesRoot = base_path('modules');
    if ($fs->isDirectory($modulesRoot)) {
        foreach ($fs->directories($modulesRoot) as $dir) {
            if (preg_match('/^.*(DocMore|DocPref|DocRoll|DocNone).*$/', $dir)) {
                $fs->deleteDirectory($dir);
            }
        }
    }
}

it('warns when filtering to a non-existent module', function (): void {
    $fs = new Filesystem();
    $backup = backupForDoctorMore();

    try {
        // Minimal composer and bootstrap
        $fs->put(base_path('composer.json'), json_encode(['name' => 'example/app'], JSON_PRETTY_PRINT));
        $fs->put(base_path('bootstrap/app.php'), "<?php\nuse Illuminate\\Foundation\\Application;\nreturn Application::configure(basePath: __DIR__.'/..')->create();\n");

        $pending = $this->artisan('ddd-lite:doctor', [
            '--module' => 'DocNone',
            '--dry-run' => true,
        ])->expectsOutputToContain('[doctor] No such module: DocNone');

        expect($pending->run())->toBe(0);
    } finally {
        restoreForDoctorMore($backup);
    }
});

it('emits dry-run actions for class/filename mismatch with prefer=class', function (): void {
    $fs = new Filesystem();
    $backup = backupForDoctorMore();

    try {
        // Ensure composer has Modules mapping so command proceeds without changing composer
        $fs->put(base_path('composer.json'), json_encode([
            'name' => 'example/app',
            'autoload' => [ 'psr-4' => [ 'App\\\\' => 'app/', 'Modules\\\\' => 'modules/' ] ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Minimal bootstrap w/out providers
        $fs->put(base_path('bootstrap/app.php'), <<<'PHP'
<?php
use Illuminate\Foundation\Application;
return Application::configure(basePath: __DIR__.'/..')
    ->withRouting(web: __DIR__.'/../routes/web.php', health: '/up')
    ->create();
PHP);

        // Create a module with a mismatched provider class vs filename
        $module = 'DocPref' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $providerDir = base_path("modules/{$module}/App/Providers");
        $fs->ensureDirectoryExists($providerDir);
        // File name XServiceProvider.php but class YServiceProvider
        $fs->put($providerDir . '/'.$module.'ServiceProvider.php', <<<PHP
<?php
namespace Modules\\{$module}\\App\\Providers;
final class {$module}WrongServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}
PHP);

        $exit = $this->artisan('ddd-lite:doctor', [
            '--module' => $module,
            '--fix' => true,
            '--dry-run' => true,
            '--prefer' => 'class',
        ])->expectsOutputToContain("[doctor][{$module}] mismatch Providers/{$module}ServiceProvider.php — prefer=class (dry-run)")
          ->run();

        expect($exit)->toBe(0);
    } finally {
        restoreForDoctorMore($backup);
    }
});

it('can perform a change and then rollback by manifest id', function (): void {
    $fs = new Filesystem();
    $backup = backupForDoctorMore();

    try {
        // composer without Modules mapping so doctor will add it when --fix is set
        $fs->put(base_path('composer.json'), json_encode(['name' => 'example/app'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        // bootstrap missing api/channels so doctor may also change when asked, but one change is enough to create manifest
        $fs->put(base_path('bootstrap/app.php'), <<<'PHP'
<?php
use Illuminate\Foundation\Application;
return Application::configure(basePath: __DIR__.'/..')
    ->withRouting(web: __DIR__.'/../routes/web.php', health: '/up')
    ->create();
PHP);

        // Run command to apply fixes (not dry-run) so a manifest is saved
        $exit = Artisan::call('ddd-lite:doctor', [ '--fix' => true ]);
        expect($exit)->toBe(0);
        $out = Artisan::output();
        // Extract manifest id
        preg_match('/Manifest:\s*([a-zA-Z0-9_-]+)/', $out, $m);
        expect($m[1] ?? null)->not->toBeNull();
        $id = (string)($m[1] ?? '');

        // Now rollback using the id
        $exit2 = Artisan::call('ddd-lite:doctor', [ '--rollback' => $id ]);
        expect($exit2)->toBe(0);
        expect(Artisan::output())->toContain("Rollback complete for {$id}.");
    } finally {
        restoreForDoctorMore($backup);
    }
});

it('fails gracefully with invalid composer.json', function (): void {
    $fs = new Filesystem();
    $backup = backupForDoctorMore();

    try {
        $fs->put(base_path('composer.json'), '{ invalid json ');
        $fs->put(base_path('bootstrap/app.php'), <<<'PHP'
<?php
use Illuminate\Foundation\Application;
return Application::configure(basePath: __DIR__.'/..')->create();
PHP);

        $code = Artisan::call('ddd-lite:doctor');
        expect($code)->toBe(1);
        expect(Artisan::output())->toContain('[doctor] error:');
    } finally {
        restoreForDoctorMore($backup);
    }
});
