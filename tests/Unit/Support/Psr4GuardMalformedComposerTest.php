<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use CreativeCrafts\DomainDrivenDesignLite\Support\Psr4Guard;
use Illuminate\Filesystem\Filesystem;

it('normalizes invalid autoload/psr-4 shapes and writes Modules mapping with backup', function (): void {
    $fs = new Filesystem();
    $composer = base_path('composer.json');

    // composer.json with malformed shapes for autoload and psr-4
    $fs->put($composer, json_encode([
        'name' => 'example/app',
        'autoload' => 'oops-not-an-array',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $guard = new Psr4Guard($fs);
    $manifest = Manifest::begin($fs);

    // Not a dry run: should coerce structures, write mapping, and track backup
    $guard->ensureModulesMapping($manifest, dryRun: false);

    $json = json_decode((string) $fs->get($composer), true, 512, JSON_THROW_ON_ERROR);

    expect($json['autoload']['psr-4']['Modules\\'] ?? null)->toBe('modules/')
        ->and(file_exists(base_path('storage/app/ddd-lite_scaffold/backups/' . sha1('composer.json') . '.bak')))->toBeTrue();
});
