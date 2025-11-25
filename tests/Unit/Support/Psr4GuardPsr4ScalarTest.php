<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use CreativeCrafts\DomainDrivenDesignLite\Support\Psr4Guard;
use Illuminate\Filesystem\Filesystem;

it('coerces non-array psr-4 value to array and injects Modules mapping', function (): void {
    $fs = new Filesystem();
    $composer = base_path('composer.json');

    // autoload is an array but psr-4 is incorrectly a string
    $fs->put($composer, json_encode([
        'name' => 'example/app',
        'autoload' => [
            'psr-4' => 'not-an-array',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $guard = new Psr4Guard($fs);
    $manifest = Manifest::begin($fs);

    $guard->ensureModulesMapping($manifest, dryRun: false);

    $json = json_decode((string) $fs->get($composer), true, 512, JSON_THROW_ON_ERROR);
    expect($json['autoload']['psr-4']['Modules\\'] ?? null)->toBe('modules/');
});
