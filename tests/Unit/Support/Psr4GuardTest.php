<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use CreativeCrafts\DomainDrivenDesignLite\Support\Psr4Guard;
use Illuminate\Filesystem\Filesystem;

it('ensures Modules mapping in composer.json in dry-run without writing', function () {
    $fs = new Filesystem();
    $composer = base_path('composer.json');

    // Minimal composer.json without Modules mapping
    $fs->put($composer, json_encode([
        'name' => 'example/app',
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/'
            ]
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $guard = new Psr4Guard($fs);
    $manifest = Manifest::begin($fs);

    // Dry run should not write but also not throw
    $guard->ensureModulesMapping($manifest, dryRun: true);

    $json = json_decode((string)$fs->get($composer), true);
    expect(isset($json['autoload']['psr-4']['Modules\\']))->toBeFalse();
});

it('writes Modules mapping and tracks via manifest when not dry-run', function () {
    $fs = new Filesystem();
    $composer = base_path('composer.json');

    $fs->put($composer, json_encode([
        'name' => 'example/app',
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/'
            ]
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $guard = new Psr4Guard($fs);
    $manifest = Manifest::begin($fs);

    $guard->ensureModulesMapping($manifest, dryRun: false);

    $json = json_decode((string)$fs->get($composer), true, 512, JSON_THROW_ON_ERROR);
    expect($json['autoload']['psr-4']['Modules\\'] ?? null)->toBe('modules/')
        ->and(file_exists(base_path('storage/app/ddd-lite_scaffold/backups/' . sha1('composer.json') . '.bak')))->toBeTrue();
});
