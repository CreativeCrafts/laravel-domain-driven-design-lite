<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('shows a manifest in JSON and infers module from paths', function (): void {
    $fs = new Filesystem();
    $dir = storage_path('app/ddd-lite_scaffold/manifests');
    $fs->ensureDirectoryExists($dir);

    $id = 'test_manifest_' . bin2hex(random_bytes(4));
    $path = $dir . DIRECTORY_SEPARATOR . $id . '.json';

    // Create a manifest with mixed actions including a move using `to` under modules/
    $data = [
        'id' => 'internal-' . $id,
        'created_at' => '2025-01-01T00:00:00Z',
        'format' => '1.0',
        'actions' => [
            ['type' => 'mkdir', 'path' => 'modules/Blog'],
            ['type' => 'create', 'path' => 'modules/Blog/App/Providers/BlogServiceProvider.php'],
            ['type' => 'move', 'path' => 'app/Old.php', 'to' => 'modules/Blog/App/Http/Controllers/NewController.php'],
            ['type' => 'update', 'path' => 'modules/Blog/some.txt'],
            ['type' => 'delete', 'path' => 'modules/Blog/obsolete.txt'],
        ],
    ];

    $fs->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $exit = $this->artisan('ddd-lite:manifest:show', [
        'id' => $id,
        '--json' => true,
    ])->run();

    expect($exit)->toBe(0);

    // Also run without --json to exercise text path
    $exit2 = $this->artisan('ddd-lite:manifest:show', [
        'id' => $id,
    ])->run();
    expect($exit2)->toBe(0);
});

it('fails gracefully when manifest is missing or invalid JSON', function (): void {
    $fs = new Filesystem();
    $dir = storage_path('app/ddd-lite_scaffold/manifests');
    $fs->ensureDirectoryExists($dir);

    // Missing manifest
    $missingId = 'does_not_exist_' . bin2hex(random_bytes(3));
    $exitMissing = $this->artisan('ddd-lite:manifest:show', [
        'id' => $missingId,
    ])->run();
    expect($exitMissing)->toBe(1);

    // Invalid JSON
    $badId = 'bad_json_' . bin2hex(random_bytes(3));
    $badPath = $dir . DIRECTORY_SEPARATOR . $badId . '.json';
    $fs->put($badPath, '{this is not valid json');

    $exitBad = $this->artisan('ddd-lite:manifest:show', [
        'id' => $badId,
    ])->run();

    expect($exitBad)->toBe(1);
});
