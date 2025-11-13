<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;

it('lists manifests and shows one by id', function (): void {
    $fs = new Filesystem();

    // Isolate via a unique module name to avoid parallel collisions
    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $module = 'Planner' . $suffix;
    $moduleRoot = base_path("modules/{$module}");
    $fs->ensureDirectoryExists($moduleRoot);

    $exit = $this->artisan('ddd-lite:make:dto', [
        'module' => $module,
        'name' => 'TripData' . $suffix,
        '--force' => true,
    ])->run();

    expect($exit)->toBe(0);

    $manifestsDir = storage_path('app/ddd-lite_scaffold/manifests');
    $fs->ensureDirectoryExists($manifestsDir);

    // Identify the manifest by content (path) rather than recency
    $relDtoPath = "modules/{$module}/Domain/DTO/TripData{$suffix}.php";

    $files = glob($manifestsDir . '/*.json') ?: [];

    expect(count($files))->toBeGreaterThan(0);

    $id = null;
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }
        $actions = $data['actions'] ?? $data['ops'] ?? [];
        if (!is_array($actions)) {
            continue;
        }
        foreach ($actions as $action) {
            $p = $action['path'] ?? $action['target'] ?? null;
            if ($p === $relDtoPath) {
                $id = $data['id'] ?? pathinfo($file, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($id)->not->toBeNull();

    // 1) Verify list runs; we don’t depend on exact formatting.
    $listExit = $this->artisan('ddd-lite:manifest:list', ['--json' => true])->run();
    expect($listExit)->toBe(0);

    // 2) Show: assert a contiguous JSON fragment for id to avoid brittle matches
    $showExit = $this->artisan('ddd-lite:manifest:show', ['id' => $id, '--json' => true])
        ->expectsOutputToContain("\"id\": \"$id\"")
        ->run();

    expect($showExit)->toBe(0);
});