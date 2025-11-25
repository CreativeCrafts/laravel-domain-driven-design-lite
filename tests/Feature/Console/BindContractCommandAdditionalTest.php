<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('writes binding and imports into provider and creates a manifest', function () {
    $fs = new Filesystem();

    $module = 'Blog';
    $providerDir = base_path("modules/{$module}/App/Providers");
    $fs->ensureDirectoryExists($providerDir);
    $providerPath = $providerDir . "/{$module}ServiceProvider.php";

    $original = <<<'PHP'
    <?php
    
    namespace Modules\Blog\App\Providers;
    
    use Illuminate\Support\ServiceProvider;
    
    final class BlogServiceProvider extends ServiceProvider
    {
        public function register(): void
        {
            // empty
        }
    }
    PHP;

    $fs->put($providerPath, $original);

    // Execute binding (non-dry), forcing class checks to be skipped to avoid autoload requirements in tests
    $this->artisan('ddd-lite:bind', [
        'module' => 'Blog',
        'contract' => 'Foo', // no "Contract" suffix provided on purpose
        // no implementation argument -> should default to Eloquent{Base}Repository
        '--force' => true,
    ])->expectsOutputToContain('Binding added. Manifest:')->run();

    $updated = (string) $fs->get($providerPath);

    // Asserts:
    // - use statements for resolved FQCNs were added
    expect($updated)
        ->toContain('use Modules\\Blog\\Domain\\Contracts\\FooContract;')
        ->toContain('use Modules\\Blog\\App\\Repositories\\EloquentFooRepository;')
        // - binding line is inserted inside register() body
        ->toContain('$this->app->bind(FooContract::class, EloquentFooRepository::class);');

    // Confirm a manifest file exists
    $manifestsDir = storage_path('app/ddd-lite_scaffold/manifests');
    expect($fs->isDirectory($manifestsDir))->toBeTrue();
    $manifestFiles = array_values(array_filter($fs->files($manifestsDir), fn ($f) => str_ends_with((string) $f, '.json')));
    expect(count($manifestFiles))->toBeGreaterThan(0);
});

it('is idempotent when binding already exists', function () {
    $fs = new Filesystem();

    $module = 'Shop';
    $providerDir = base_path("modules/{$module}/App/Providers");
    $fs->ensureDirectoryExists($providerDir);
    $providerPath = $providerDir . "/{$module}ServiceProvider.php";

    $provider = <<<'PHP'
    <?php
    
    namespace Modules\Shop\App\Providers;
    
    use Illuminate\Support\ServiceProvider;
    
    final class ShopServiceProvider extends ServiceProvider
    {
        public function register(): void
        {
            // empty
        }
    }
    PHP;

    $fs->put($providerPath, $provider);

    // First run writes the binding
    $this->artisan('ddd-lite:bind', [
        'module' => 'Shop',
        'contract' => 'BarContract',
        'implementation' => 'EloquentBarRepository',
        '--force' => true,
    ])->run();

    $afterFirst = (string) $fs->get($providerPath);

    // Second run should detect no changes
    $this->artisan('ddd-lite:bind', [
        'module' => 'Shop',
        'contract' => 'BarContract',
        'implementation' => 'EloquentBarRepository',
        '--force' => true,
    ])->expectsOutput('No changes detected.')
      ->run();

    $afterSecond = (string) $fs->get($providerPath);
    expect($afterSecond)->toBe($afterFirst);
});

it('can rollback a previous binding and restore provider', function () {
    $fs = new Filesystem();

    $module = 'Forum';
    $providerDir = base_path("modules/{$module}/App/Providers");
    $fs->ensureDirectoryExists($providerDir);
    $providerPath = $providerDir . "/{$module}ServiceProvider.php";

    $original = <<<'PHP'
    <?php
    
    namespace Modules\Forum\App\Providers;
    
    use Illuminate\Support\ServiceProvider;
    
    final class ForumServiceProvider extends ServiceProvider
    {
        public function register(): void
        {
            // empty
        }
    }
    PHP;

    $fs->put($providerPath, $original);

    // Snapshot manifests before run
    $manifestsDir = storage_path('app/ddd-lite_scaffold/manifests');
    $before = collect($fs->isDirectory($manifestsDir) ? $fs->files($manifestsDir) : [])
        ->filter(fn ($f) => str_ends_with((string) $f, '.json'))
        ->map(fn ($f) => (string) $f)
        ->values()
        ->all();

    // Perform a binding to generate a manifest
    $this->artisan('ddd-lite:bind', [
        'module' => 'Forum',
        'contract' => 'Baz',
        '--force' => true,
    ])->run();

    // Compute new manifests after run and diff to get the new one
    $after = collect($fs->isDirectory($manifestsDir) ? $fs->files($manifestsDir) : [])
        ->filter(fn ($f) => str_ends_with((string) $f, '.json'))
        ->map(fn ($f) => (string) $f)
        ->values()
        ->all();

    $diff = array_values(array_diff($after, $before));
    expect(count($diff))->toBeGreaterThan(0);
    $manifestPath = $diff[array_key_last($diff)];
    $manifestId = basename($manifestPath, '.json');

    // Rollback using the manifest id
    $this->artisan('ddd-lite:bind', [
        'module' => 'Forum', // ignored by --rollback path
        'contract' => 'Baz', // ignored
        '--rollback' => $manifestId,
    ])->expectsOutput("Rollback complete for {$manifestId}.")
      ->run();

    // Provider restored
    expect((string) $fs->get($providerPath))->toBe($original);

    // And manifest file is removed
    expect($fs->exists($manifestPath))->toBeFalse();
});
