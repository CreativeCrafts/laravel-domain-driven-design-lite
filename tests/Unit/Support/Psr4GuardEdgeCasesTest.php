<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use CreativeCrafts\DomainDrivenDesignLite\Support\Psr4Guard;
use Illuminate\Filesystem\Filesystem;

it('throws when composer.json is missing', function (): void {
    $fs = new Filesystem();
    $composer = base_path('composer.json');

    $had = $fs->exists($composer);
    $backup = $had ? (string)$fs->get($composer) : '';

    try {
        if ($had) {
            $fs->delete($composer);
        }

        $guard = new Psr4Guard($fs);
        $manifest = Manifest::begin($fs);

        $call = fn () => $guard->ensureModulesMapping($manifest, dryRun: true);
        expect($call)->toThrow(RuntimeException::class, 'composer.json not found');
    } finally {
        if ($had) {
            $fs->put($composer, $backup);
        } else {
            // recreate a minimal valid composer.json to avoid cross-test interference
            $fs->put($composer, json_encode(['name' => 'example/app'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
});

it('throws a friendly error when composer.json JSON is valid but not an object', function (): void {
    $fs = new Filesystem();
    $composer = base_path('composer.json');

    // JSON that decodes to null, which is valid JSON but not an array/object for our purposes
    $fs->put($composer, 'null');

    $guard = new Psr4Guard($fs);
    $manifest = Manifest::begin($fs);

    $call = fn () => $guard->ensureModulesMapping($manifest, dryRun: true);
    expect($call)->toThrow(RuntimeException::class, 'composer.json is not valid JSON.');
});

it('assertOrFixCase is a no-op (no log) when neither correct nor lower dir exists', function (): void {
    $fs = new Filesystem();
    $modulesRoot = base_path('modules');
    $fs->ensureDirectoryExists($modulesRoot);

    $module = 'NoDirs' . bin2hex(random_bytes(3));

    $messages = [];
    $guard = new Psr4Guard($fs);
    $guard->assertOrFixCase($module, dryRun: false, fix: true, log: function (string $m) use (&$messages): void {
        $messages[] = $m;
    });

    expect($messages)->toBeArray()->toBeEmpty();
});
