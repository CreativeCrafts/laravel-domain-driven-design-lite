<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('dry-run injects binding plan into provider without writing when forced', function () {
    $fs = new Filesystem();

    // Set up a synthetic module with a minimal ServiceProvider
    $module = 'Blog';
    $providerDir = base_path("modules/{$module}/App/Providers");
    $fs->ensureDirectoryExists($providerDir);
    $providerPath = $providerDir . "/{$module}ServiceProvider.php";

    $code = <<<'PHP'
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

    $fs->put($providerPath, $code);

    // Run bind in dry-run with --force so it doesn't require classes to exist
    $exit = $this->artisan('ddd-lite:bind', [
        'module' => 'Blog',
        'contract' => 'FooContract',
        'implementation' => 'Modules\\Blog\\App\\Repositories\\EloquentFooRepository',
        '--dry-run' => true,
        '--force' => true,
    ])->run();

    // Provider file should remain unchanged and command should succeed
    expect($exit)->toBe(0)
        ->and((string)$fs->get($providerPath))->toBe($code);
});
