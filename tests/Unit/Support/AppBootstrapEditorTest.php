<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\AppBootstrapEditor;
use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $fs = new Filesystem();
    $fs->ensureDirectoryExists(base_path('bootstrap'));
    $path = base_path('bootstrap/app.php');
    $_SERVER['__orig_bootstrap_exists'] = $fs->exists($path) ? '1' : '0';
    $_SERVER['__orig_bootstrap'] = $fs->exists($path) ? (string)$fs->get($path) : '';
});

afterEach(function () {
    $fs = new Filesystem();
    $path = base_path('bootstrap/app.php');
    $had = ($_SERVER['__orig_bootstrap_exists'] ?? '0') === '1';
    if ($had) {
        $fs->put($path, (string)($_SERVER['__orig_bootstrap'] ?? ''));
    } else {
        if ($fs->exists($path)) {
            $fs->delete($path);
        }
    }
});

function putBootstrap(string $code): void
{
    $fs = new Filesystem();
    $fs->put(base_path('bootstrap/app.php'), $code);
}

it('injects withProviders after configure when none exists', function (): void {
    $fs = new Filesystem();
    $editor = new AppBootstrapEditor($fs);
    $manifest = Manifest::begin($fs);

    $src = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;
    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
        )
        ->create();
    PHP;

    putBootstrap($src);

    $editor->ensureModuleProvider($manifest, 'Blog', 'BlogServiceProvider');

    $updated = (string) $fs->get(base_path('bootstrap/app.php'));

    expect($updated)->toContain('->withProviders([')
        ->and($updated)->toContain('Modules\\Blog\\App\\Providers\\BlogServiceProvider::class')
        ->and($updated)->toMatch('/return\s+Application::configure[\s\S]*->withProviders\(\[[\s\S]*\][\s\S]*->withRouting\(/');
});

it('appends provider to existing withProviders list', function (): void {
    $fs = new Filesystem();
    $editor = new AppBootstrapEditor($fs);
    $manifest = Manifest::begin($fs);

    $src = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;
    return Application::configure(basePath: dirname(__DIR__))
        ->withProviders([
            Modules\Core\App\Providers\CoreServiceProvider::class,
        ])
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
        )
        ->create();
    PHP;

    putBootstrap($src);

    $editor->ensureModuleProvider($manifest, 'Blog', 'BlogServiceProvider');

    $updated = (string) $fs->get(base_path('bootstrap/app.php'));

    // should keep existing and add new provider within the withProviders block
    expect($updated)
        ->toContain('->withProviders([')
        ->and($updated)->toContain('Modules\\Core\\App\\Providers\\CoreServiceProvider::class')
        ->and($updated)->toContain('Modules\\Blog\\App\\Providers\\BlogServiceProvider::class');
});

it('removes standalone $app->withProviders blocks', function (): void {
    $fs = new Filesystem();
    $editor = new AppBootstrapEditor($fs);
    $manifest = Manifest::begin($fs);

    $src = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;

    $app = new stdClass();
    $app->withProviders([
        Modules\Foo\Providers\FooServiceProvider::class,
    ]);

    return Application::configure(basePath: dirname(__DIR__))
        ->create();
    PHP;

    putBootstrap($src);

    $editor->removeStandaloneWithProviders($manifest);

    $updated = (string) $fs->get(base_path('bootstrap/app.php'));
    expect($updated)->not->toContain('$app->withProviders(')
        ->and($updated)->toContain('return Application::configure');
});

it('ensures routing keys by injecting when missing and appending when partially present', function (): void {
    $fs = new Filesystem();
    $editor = new AppBootstrapEditor($fs);
    $manifest = Manifest::begin($fs);

    // Case 1: No withRouting in chain -> inject
    $src1 = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;
    return Application::configure(basePath: dirname(__DIR__))
        ->withProviders([
            Modules\Core\App\Providers\CoreServiceProvider::class,
        ])
        ->create();
    PHP;
    putBootstrap($src1);

    $editor->ensureRoutingKeys($manifest, [
        'web' => "__DIR__.'/../routes/web.php'",
        'commands' => "__DIR__.'/../routes/console.php'",
        'health' => "'/up'",
    ]);

    $updated1 = (string) $fs->get(base_path('bootstrap/app.php'));
    expect($updated1)->toMatch('/->withRouting\([\s\S]*web:\s*[\s\S]*commands:\s*[\s\S]*health:\s*[\s\S]*\)/');

    // Case 2: withRouting exists with one key; ensure others appended with commas
    $src2 = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;
    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php'
        )
        ->create();
    PHP;
    putBootstrap($src2);

    $editor->ensureRoutingKeys($manifest, [
        'web' => "__DIR__.'/../routes/web.php'",
        'commands' => "__DIR__.'/../routes/console.php'",
        'health' => "'/up'",
    ]);

    $updated2 = (string) $fs->get(base_path('bootstrap/app.php'));
    expect($updated2)
        ->toContain('web: __DIR__')
        // allow optional comma/newline formatting between arguments
        ->and($updated2)->toMatch('/withRouting\([\s\S]*web:\s*[\s\S]*commands:\s*[\s\S]*health:\s*[\s\S]*\)/');

    // Case 3: Idempotent when all keys present
    $before = $updated2;
    $editor->ensureRoutingKeys($manifest, [
        'web' => "__DIR__.'/../routes/web.php'",
        'commands' => "__DIR__.'/../routes/console.php'",
        'health' => "'/up'",
    ]);
    $after = (string)$fs->get(base_path('bootstrap/app.php'));
    expect($after)->toBe($before);
});
