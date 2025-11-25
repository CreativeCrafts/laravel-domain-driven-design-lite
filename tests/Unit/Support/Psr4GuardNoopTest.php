<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use CreativeCrafts\DomainDrivenDesignLite\Support\Psr4Guard;
use Illuminate\Filesystem\Filesystem;

it('does nothing when Modules mapping already exists and when correct casing folder exists', function (): void {
    $fs = new Filesystem();

    // composer.json already has mapping
    $composer = base_path('composer.json');
    $fs->put($composer, json_encode([
        'name' => 'example/app',
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
                'Modules\\' => 'modules/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $manifest = Manifest::begin($fs);
    $guard = new Psr4Guard($fs);

    // Should return without changes
    $guard->ensureModulesMapping($manifest, dryRun: false);
    $json = json_decode((string)$fs->get($composer), true, 512, JSON_THROW_ON_ERROR);
    expect($json['autoload']['psr-4']['Modules\\'] ?? null)->toBe('modules/');

    // Correct casing folder already exists -> no-op
    $modulesRoot = base_path('modules');
    $fs->ensureDirectoryExists($modulesRoot);
    $module = 'Blog';
    $fs->ensureDirectoryExists($modulesRoot . DIRECTORY_SEPARATOR . $module);

    $messages = [];
    $guard->assertOrFixCase($module, dryRun: false, fix: true, log: function (string $m) use (&$messages): void {
        $messages[] = $m;
    });

    // No rename should occur and no message logged because target exists
    expect($messages)->toBeArray()->toBeEmpty();
});
