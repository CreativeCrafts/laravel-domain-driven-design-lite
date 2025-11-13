<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('publishes all quality artifacts', function (): void {
    $fs = new Filesystem();

    $phpstan = base_path('phpstan.neon');
    $deptrac = base_path('deptrac.yaml');
    $pest = base_path('tests/Architecture/ArchitectureTest.php');

    foreach ([$phpstan, $deptrac, $pest] as $p) {
        if ($fs->exists($p)) {
            $fs->delete($p);
        }
    }
    $fs->deleteDirectory(dirname($pest));

    $exit = $this->artisan('ddd-lite:publish:quality', [
        '--target' => 'all',
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($phpstan))->toBeTrue()
        ->and($fs->exists($deptrac))->toBeTrue()
        ->and($fs->exists($pest))->toBeTrue();
});

it('honors dry-run (no writes)', function (): void {
    $fs = new Filesystem();

    $phpstan = base_path('phpstan.neon');
    if ($fs->exists($phpstan)) {
        $fs->delete($phpstan);
    }

    $exit = $this->artisan('ddd-lite:publish:quality', [
        '--target' => 'phpstan',
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($phpstan))->toBeFalse();
});

it('is idempotent when unchanged', function (): void {
    $fs = new Filesystem();

    $phpstan = base_path('phpstan.neon');

    $this->artisan('ddd-lite:publish:quality', [
        '--target' => 'phpstan',
    ])->run();

    $mTime = $fs->exists($phpstan) ? $fs->lastModified($phpstan) : 0;

    \usleep(1000);

    $exit = $this->artisan('ddd-lite:publish:quality', [
        '--target' => 'phpstan',
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->lastModified($phpstan))->toBe($mTime);
});

it('overwrites with --force and creates a backup', function (): void {
    $fs = new Filesystem();

    $phpstan = base_path('phpstan.neon');
    $fs->put($phpstan, "# original\n");

    $exit = $this->artisan('ddd-lite:publish:quality', [
        '--target' => 'phpstan',
        '--force' => true,
    ])->run();

    expect($exit)->toBe(0);

    $code = (string)$fs->get($phpstan);
    expect($code)->toContain('parameters');

    $backup = base_path('storage/app/ddd-lite_scaffold/backups/' . sha1($phpstan) . '.bak');
    expect($fs->exists($backup))->toBeTrue();
});

it('supports rollback by manifest id', function (): void {
    $fs = new Filesystem();

    $phpstan = base_path('phpstan.neon');
    if ($fs->exists($phpstan)) {
        $fs->delete($phpstan);
    }

    // Ensure manifests directory exists but do not assume counts (parallel-safe)
    $manifestsDir = storage_path('app/ddd-lite_scaffold/manifests');
    $fs->ensureDirectoryExists($manifestsDir);

    $exit = $this->artisan('ddd-lite:publish:quality', [
        '--target' => 'phpstan',
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($phpstan))->toBeTrue();

    // Find the manifest whose actions reference phpstan.neon
    $rel = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $phpstan), DIRECTORY_SEPARATOR);
    $files = glob($manifestsDir . '/*.json') ?: [];
    $manifestId = null;
    foreach ($files as $file) {
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
        continue;
        }
        foreach (($data['actions'] ?? $data['ops'] ?? []) as $action) {
            $p = $action['path'] ?? $action['target'] ?? null;
            if ($p === $rel) {
                $manifestId = $data['id'] ?? pathinfo($file, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($manifestId)->not->toBeNull();

    $exit2 = $this->artisan('ddd-lite:publish:quality', [
        '--rollback' => $manifestId,
    ])->run();

    expect($exit2)->toBe(0);
});

it('dry-run does not write any files for phpstan target', function (): void {
    $fs = new Filesystem();

    $dest = base_path('phpstan.neon');

    // Ensure a clean slate
    if ($fs->exists($dest)) {
        $fs->delete($dest);
    }
    expect($fs->exists($dest))->toBeFalse();

    // Run publish in dry-run mode
    $exit = $this->artisan('ddd-lite:publish:quality', [
        '--target' => 'phpstan',
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($dest))->toBeFalse();
});

it('overwrites with --force and creates a backup for phpstan target', function (): void {
    $fs = new Filesystem();

    $dest = base_path('phpstan.neon');
    $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($dest) . '.bak');

    // Prepare an existing file with sentinel content
    $fs->ensureDirectoryExists(dirname($dest));
    $original = "// sentinel: before publish\n";
    $fs->put($dest, $original);

    // Ensure no stale backup remains
    if ($fs->exists($backup)) {
        $fs->delete($backup);
    }

    // Run publication with --force (should overwrite and create backup)
    $exit = $this->artisan('ddd-lite:publish:quality', [
        '--target' => 'phpstan',
        '--force' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($backup))->toBeTrue()
        ->and((string)$fs->get($backup))->toBe($original)
        ->and($fs->exists($dest))->toBeTrue()
        ->and((string)$fs->get($dest))->not()->toBe($original);
});
