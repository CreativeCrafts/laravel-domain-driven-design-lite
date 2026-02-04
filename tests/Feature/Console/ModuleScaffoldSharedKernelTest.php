<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('scaffolds a shared kernel module without routes or http layer', function (): void {
    $fs = new Filesystem();
    $module = 'Shared';

    $fs->deleteDirectory(base_path("modules/{$module}"));

    // Ensure composer.json has Modules\\ mapping so Psr4Guard doesn't fail.
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
    }

    $exit = $this->artisan('ddd-lite:module', [
        'name' => $module,
        '--shared' => true,
        '--yes' => true,
        '--force' => true,
    ])->run();

    expect($exit)->toBe(0);

    expect($fs->isDirectory(base_path("modules/{$module}/Domain")))->toBeTrue()
        ->and($fs->isDirectory(base_path("modules/{$module}/Domain/ValueObjects")))->toBeTrue()
        ->and($fs->isDirectory(base_path("modules/{$module}/Domain/Exceptions")))->toBeTrue()
        ->and($fs->isDirectory(base_path("modules/{$module}/Routes")))->toBeFalse()
        ->and($fs->isDirectory(base_path("modules/{$module}/App/Http")))->toBeFalse();
});
