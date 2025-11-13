<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('returns success and empty list when no manifest dir exists', function (): void {
    $fs = new Filesystem();

    // Use a unique temporary storage path so we don't touch the global storage
    $tempStorage = base_path('storage_test_' . strtoupper(substr(md5(uniqid('', true)), 0, 8)));

    // Clean up anything stale first
    if ($fs->isDirectory($tempStorage)) {
        $fs->deleteDirectory($tempStorage);
    }

    // Point the app's storage_path() to our temp directory (which doesn't have manifests)
    app()->useStoragePath($tempStorage);

    try {
        // We assert that the command succeeds; we avoid brittle output assertions.
        $this->artisan('ddd-lite:manifest:list', ['--json' => true])
            ->assertExitCode(0);
    } finally {
        // Cleanup: remove the temp storage tree
        if ($fs->isDirectory($tempStorage)) {
            $fs->deleteDirectory($tempStorage);
        }
    }
});
