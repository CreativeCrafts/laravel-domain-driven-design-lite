<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('diffs and syncs stubs into app stubs directory', function (): void {
    $fs = new Filesystem();

    $base = base_path('stubs/ddd-lite-base');
    $custom = base_path('stubs/ddd-lite-test');

    $fs->deleteDirectory($base);
    $fs->deleteDirectory($custom);
    $fs->ensureDirectoryExists($base);

    $fs->put($base . '/value-object.stub', "base\n");

    $exitDiff = $this->artisan('ddd-lite:stubs:diff', [
        '--base' => $base,
        '--custom' => $custom,
        '--json' => true,
    ])->run();

    expect($exitDiff)->toBe(0);

    $exitSync = $this->artisan('ddd-lite:stubs:sync', [
        '--base' => $base,
        '--custom' => $custom,
        '--mode' => 'missing',
    ])->run();

    expect($exitSync)->toBe(0)
        ->and($fs->exists($custom . '/value-object.stub'))->toBeTrue();
});
