<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('filters by module and type and time window', function (): void {
    $fs = new Filesystem();
    $dir = storage_path('app/ddd-lite_scaffold/manifests');

    // Make the directory exist but DO NOT clean it globally – that would race
    // with other tests running in parallel that also rely on manifests.
    $fs->ensureDirectoryExists($dir);

    // Create three synthetic manifests that this test fully owns.
    $m1 = $dir . '/aaa111.json';
    $m2 = $dir . '/bbb222.json';
    $m3 = $dir . '/ccc333.json';

    // Ensure we start from a clean slate for *these specific* files only.
    foreach ([$m1, $m2, $m3] as $file) {
        if ($fs->exists($file)) {
            $fs->delete($file);
        }
    }

    $fs->put($m1, json_encode([
        'id' => 'aaa111',
        'created_at' => '2025-11-10T10:00:00+00:00',
        'actions' => [
            ['type' => 'create', 'path' => 'modules/Planner/App/Http/Controllers/X.php'],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $fs->put($m2, json_encode([
        'id' => 'bbb222',
        'created_at' => '2025-11-11T12:00:00+00:00',
        'actions' => [
            ['type' => 'update', 'path' => 'modules/Billing/App/Models/Y.php'],
            ['type' => 'move', 'path' => 'app/Old.php', 'to' => 'modules/Planner/App/New.php'],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $fs->put($m3, json_encode([
        'id' => 'ccc333',
        'created_at' => '2025-10-01T09:00:00+00:00',
        'actions' => [
            ['type' => 'mkdir', 'path' => 'modules/Legacy/.keep'],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Sanity: ensure the files really exist where the command will read them.
    expect($fs->exists($m1))->toBeTrue()
        ->and($fs->exists($m2))->toBeTrue()
        ->and($fs->exists($m3))->toBeTrue();

    // 1) Filter by module=Planner (must at least include the Planner create manifest, and exclude ccc333)
    $this->artisan('ddd-lite:manifest:list', [
        '--json' => true,
        '--module' => 'Planner',
    ])
        ->expectsOutputToContain('"aaa111"')
        ->doesntExpectOutputToContain('"ccc333"')
        ->assertExitCode(0);

    // 2) Filter by type=create (only m1)
    $this->artisan('ddd-lite:manifest:list', [
        '--json' => true,
        '--type' => 'create',
    ])
        ->expectsOutputToContain('"aaa111"')
        ->doesntExpectOutputToContain('"bbb222"')
        ->doesntExpectOutputToContain('"ccc333"')
        ->assertExitCode(0);

    // 3) Filter by time window after=2025-11-11 (only m2)
    $this->artisan('ddd-lite:manifest:list', [
        '--json' => true,
        '--after' => '2025-11-11T00:00:00+00:00',
    ])
        ->expectsOutputToContain('"bbb222"')
        ->doesntExpectOutputToContain('"aaa111"')
        ->doesntExpectOutputToContain('"ccc333"')
        ->assertExitCode(0);

    // 4) Combine module + type (Planner + move) → only m2
    $this->artisan('ddd-lite:manifest:list', [
        '--json' => true,
        '--module' => 'Planner',
        '--type' => 'move',
    ])
        ->expectsOutputToContain('"bbb222"')
        ->doesntExpectOutputToContain('"aaa111"')
        ->doesntExpectOutputToContain('"ccc333"')
        ->assertExitCode(0);
});
