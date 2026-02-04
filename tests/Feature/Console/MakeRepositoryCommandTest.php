<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('previews and generates an Eloquent repository with default test', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerRepositoryDemo';
    $moduleRoot = base_path("modules/{$module}");
    $reposDir = base_path("modules/{$module}/App/Repositories");
    $testsDir = base_path("modules/{$module}/tests/Unit/App/Repositories");

    $fs->ensureDirectoryExists($reposDir);

    $repoPath = base_path("modules/{$module}/App/Repositories/EloquentTripRepository.php");
    $testPath = base_path("modules/{$module}/tests/Unit/App/Repositories/EloquentTripRepositoryTest.php");

    // Clean any leftovers
    foreach ([$repoPath, $testPath] as $p) {
        if ($fs->exists($p)) {
            $fs->delete($p);
        }
    }

    // Dry run should succeed and not create files
    $exitDry = $this->artisan('ddd-lite:make:repository', [
        'module' => $module,
        'aggregate' => 'Trip',
        '--dry-run' => true,
    ])->run();

    expect($exitDry)->toBe(0)
        ->and($fs->exists($repoPath))->toBeFalse()
        ->and($fs->exists($testPath))->toBeFalse();

    // Actual run should create repo and test
    $exit = $this->artisan('ddd-lite:make:repository', [
        'module' => $module,
        'aggregate' => 'Trip',
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($repoPath))->toBeTrue()
        ->and($fs->exists($testPath))->toBeTrue();

    // Basic content checks
    $repoCode = (string) $fs->get($repoPath);
    expect($repoCode)
        ->toContain('namespace Modules\\' . $module . '\\App\\Repositories;')
        ->and($repoCode)->toContain('final class EloquentTripRepository');

    $testCode = (string) $fs->get($testPath);
    expect($testCode)
        ->toContain('EloquentTripRepository');

    // Existing file without --force should fail
    $this->artisan('ddd-lite:make:repository', [
        'module' => $module,
        'aggregate' => 'Trip',
    ])->assertExitCode(1);

    // With --no-test, only the repository should be written
    // First, clean up test file and repo to ensure deterministic behavior
    foreach ([$repoPath, $testPath] as $p) {
        if ($fs->exists($p)) {
            $fs->delete($p);
        }
    }

    $exitNoTest = $this->artisan('ddd-lite:make:repository', [
        'module' => $module,
        'aggregate' => 'Trip',
        '--no-test' => true,
    ])->run();

    expect($exitNoTest)->toBe(0)
        ->and($fs->exists($repoPath))->toBeTrue()
        ->and($fs->exists($testPath))->toBeFalse();
});

it('supports rollback for repository generation', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerRepositoryDemo';
    $reposDir = base_path("modules/{$module}/App/Repositories");
    $testsDir = base_path("modules/{$module}/tests/Unit/App/Repositories");
    $fs->ensureDirectoryExists($reposDir);

    $repoPath = base_path("modules/{$module}/App/Repositories/EloquentItineraryRepository.php");
    $testPath = base_path("modules/{$module}/tests/Unit/App/Repositories/EloquentItineraryRepositoryTest.php");

    foreach ([$repoPath, $testPath] as $p) {
        if ($fs->exists($p)) {
            $fs->delete($p);
        }
    }

    $manifestDir = storage_path('app/ddd-lite_scaffold/manifests');
    $before = glob($manifestDir . '/*.json') ?: [];

    $this->artisan('ddd-lite:make:repository', [
        'module' => $module,
        'aggregate' => 'Itinerary',
    ])->run();

    expect($fs->exists($repoPath))->toBeTrue();

    $after = glob($manifestDir . '/*.json') ?: [];
    $new = array_values(array_diff($after, $before));

    $repoRel = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $repoPath), DIRECTORY_SEPARATOR);
    $testRel = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $testPath), DIRECTORY_SEPARATOR);

    $manifestId = null;

    foreach ($new as $file) {
        if (!is_file($file)) {
            continue;
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $actions = $data['actions'] ?? [];
        foreach ($actions as $action) {
            if (
                ($action['type'] === 'create' || $action['type'] === 'update')
                && in_array($action['path'], [$repoRel, $testRel], true)
            ) {
                $manifestId = pathinfo($file, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($manifestId)->not->toBeNull();

    $this->artisan('ddd-lite:make:repository', [
        '--rollback' => $manifestId,
    ])->run();

    expect($fs->exists($repoPath))->toBeFalse();
    // Test file may or may not exist depending on previous steps, but after rollback it should be gone
    expect($fs->exists($testPath))->toBeFalse();
});
