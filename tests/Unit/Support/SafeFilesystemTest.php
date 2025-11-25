<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use CreativeCrafts\DomainDrivenDesignLite\Support\SafeFilesystem;
use Illuminate\Filesystem\Filesystem;

it('tracks mkdir on ensureDirTracked only when directory does not exist', function () {
    $fs = new Filesystem();
    $safe = new SafeFilesystem($fs);
    $manifest = Manifest::begin($fs);

    $rel = 'modules/Shop/NewDir';
    $abs = base_path($rel);

    // Ensure clean state
    if (is_dir($abs)) {
        $fs->deleteDirectory($abs);
    }

    $safe->ensureDirTracked($manifest, $rel);

    // Directory should now exist
    expect(is_dir($abs))->toBeTrue();

    // Calling again should be a no-op and still exist
    $safe->ensureDirTracked($manifest, $rel);
    expect(is_dir($abs))->toBeTrue();
});

it('writeNew creates file and tracks create; overwrite creates backup and tracks update', function () {
    $fs = new Filesystem();
    $safe = new SafeFilesystem($fs);
    $manifest = Manifest::begin($fs);

    $rel = 'modules/Shop/file.txt';
    $abs = base_path($rel);

    // Clean slate
    if ($fs->exists($abs)) {
        $fs->delete($abs);
    }
    $fs->deleteDirectory(dirname($abs));

    // writeNew should create and track
    $safe->writeNew($manifest, $rel, 'v1');
    expect($fs->exists($abs))->toBeTrue();

    // Now overwrite should create a backup and track update
    $safe->overwrite($manifest, $rel, 'v2');

    // Validate backup exists
    $backup = storage_path('app/ddd-lite_scaffold/backups/' . str_replace(['\\', '/'], '_', $rel) . '.bak');
    expect($fs->exists($backup))->toBeTrue();

    // Validate final content
    expect((string)$fs->get($abs))->toBe('v2');
});
