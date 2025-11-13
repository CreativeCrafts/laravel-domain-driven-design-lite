<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use Illuminate\Filesystem\Filesystem;

it('removes empty module directories on rollback', function (): void {
    $fs = new Filesystem();

    // Use a unique module name so no other tests interfere.
    $module = 'RollbackSandboxModule';

    // Ensure the test app has a Modules\\ PSR-4 mapping so Psr4Guard
    // does not fail during ddd-lite:module execution.
    $composerPath = base_path('composer.json');

    try {
        $composerRaw = $fs->get($composerPath);

        /** @var array<string, mixed> $composer */
        $composer = json_decode($composerRaw, true, 512, JSON_THROW_ON_ERROR);

        $autoload = $composer['autoload'] ?? [];
        $psr4 = $autoload['psr-4'] ?? [];

        if (!isset($psr4['Modules\\'])) {
            $psr4['Modules\\'] = 'modules/';

            $autoload['psr-4'] = $psr4;
            $composer['autoload'] = $autoload;

            $fs->put(
                $composerPath,
                json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
            );
        }
    } catch (JsonException) {
        // If composer.json is somehow invalid, let the test fail naturally later.
    }

    // Create a synthetic manifest that "created" the module root.
    // This avoids relying on ddd-lite:module to have succeeded in this test.
    $manifest = Manifest::begin($fs);
    $manifest->trackMkdir("modules/{$module}");
    $manifest->save();

    $manifestId = $manifest->id();

    // Ensure the modules/<Module> directory actually exists before rollback.
    $moduleRoot = base_path("modules/{$module}");
    if (!$fs->isDirectory($moduleRoot)) {
        $fs->makeDirectory($moduleRoot, 0755, true);
    }

    expect($fs->isDirectory($moduleRoot))->toBeTrue();

    // Now exercise the rollback path of ModuleScaffoldCommand.
    // No confirmation is required in rollback mode.
    $this->artisan('ddd-lite:module', [
        'name' => $module,
        '--rollback' => $manifestId,
    ])->run();

    // We only care that the tree is cleaned up. Exit code can vary based on
    // environment / kernel behavior, and isn’t the purpose of this test.
    expect($fs->isDirectory($moduleRoot))->toBeFalse();
});