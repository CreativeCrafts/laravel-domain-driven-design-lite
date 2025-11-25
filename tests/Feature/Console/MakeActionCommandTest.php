<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('previews and generates a Domain Action with optional subnamespace and defaults', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerActionDemo';
    $moduleRoot = base_path("modules/{$module}");
    $actionsRoot = base_path("modules/{$module}/Domain/Actions");
    $testsRoot = base_path("modules/{$module}/tests/Unit/Domain/Actions");

    // Ensure base directories exist for deterministic behavior
    $fs->ensureDirectoryExists($actionsRoot);

    // With --in=Trip
    $phpPath = base_path("modules/{$module}/Domain/Actions/Trip/CreateTripAction.php");
    $testPath = base_path("modules/{$module}/tests/Unit/Domain/Actions/CreateTripActionTest.php");

    foreach ([$phpPath, $testPath] as $p) {
        if ($fs->exists($p)) {
            $fs->delete($p);
        }
    }

    // Dry run should not write
    $exitDry = $this->artisan('ddd-lite:make:action', [
        'module' => $module,
        'name' => 'CreateTrip',
        '--in' => 'Trip',
        '--dry-run' => true,
    ])->run();

    expect($exitDry)->toBe(0)
        ->and($fs->exists($phpPath))->toBeFalse()
        ->and($fs->exists($testPath))->toBeFalse();

    // Actual run with specific options for parameters/returns
    $exit = $this->artisan('ddd-lite:make:action', [
        'module' => $module,
        'name' => 'CreateTrip',
        '--in' => 'Trip',
        '--input' => 'ulid',
        '--param' => 'id',
        '--returns' => 'ulid',
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($phpPath))->toBeTrue()
        ->and($fs->exists($testPath))->toBeTrue();

    $code = (string) $fs->get($phpPath);
    // Namespace and class name
    expect($code)
        ->toContain('namespace Modules\\' . $module . '\\Domain\\Actions\\Trip;')
        ->and($code)->toContain('final class CreateTripAction')
        // Imports and signature for ulid param and return type
        ->and($code)->toContain('use Symfony\\Component\\Uid\\Ulid;')
        ->and($code)->toContain('public function __invoke(Ulid $id): Ulid');

    // Creating again without --force should be a no-op success when content is identical
    $this->artisan('ddd-lite:make:action', [
        'module' => $module,
        'name' => 'CreateTrip',
        '--in' => 'Trip',
        '--input' => 'ulid',
        '--param' => 'id',
        '--returns' => 'ulid',
    ])->assertExitCode(0);

    // Generate another action with --no-test to ensure test file is not written
    $phpPath2 = base_path("modules/{$module}/Domain/Actions/Archive/CloseTripAction.php");
    $testPath2 = base_path("modules/{$module}/tests/Unit/Domain/Actions/CloseTripActionTest.php");
    foreach ([$phpPath2, $testPath2] as $p) {
        if ($fs->exists($p)) {
            $fs->delete($p);
        }
    }

    $exitNoTest = $this->artisan('ddd-lite:make:action', [
        'module' => $module,
        'name' => 'CloseTrip',
        '--in' => 'Archive',
        '--no-test' => true,
    ])->run();

    expect($exitNoTest)->toBe(0)
        ->and($fs->exists($phpPath2))->toBeTrue()
        ->and($fs->exists($testPath2))->toBeFalse();
});

it('supports rollback for action generation', function (): void {
    $fs = new Filesystem();

    $module = 'PlannerActionDemo';
    $actionsRoot = base_path("modules/{$module}/Domain/Actions");
    $fs->ensureDirectoryExists($actionsRoot);

    $phpPath = base_path("modules/{$module}/Domain/Actions/DeleteTripAction.php");
    $testPath = base_path("modules/{$module}/tests/Unit/Domain/Actions/DeleteTripActionTest.php");

    foreach ([$phpPath, $testPath] as $p) {
        if ($fs->exists($p)) {
            $fs->delete($p);
        }
    }

    $manifestDir = storage_path('app/ddd-lite_scaffold/manifests');
    $before = glob($manifestDir . '/*.json') ?: [];

    $this->artisan('ddd-lite:make:action', [
        'module' => $module,
        'name' => 'DeleteTrip',
    ])->run();

    expect($fs->exists($phpPath))->toBeTrue();

    $after = glob($manifestDir . '/*.json') ?: [];
    $new = array_values(array_diff($after, $before));

    $relPhp = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $phpPath), DIRECTORY_SEPARATOR);
    $relTest = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $testPath), DIRECTORY_SEPARATOR);

    $manifestId = null;
    foreach ($new as $file) {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $actions = $data['actions'] ?? [];
        foreach ($actions as $action) {
            if (
                ($action['type'] === 'create' || $action['type'] === 'update')
                && in_array($action['path'], [$relPhp, $relTest], true)
            ) {
                $manifestId = pathinfo($file, PATHINFO_FILENAME);
                break 2;
            }
        }
    }

    expect($manifestId)->not->toBeNull();

    // rollback without providing module/name args (should be accepted)
    $this->artisan('ddd-lite:make:action', [
        '--rollback' => $manifestId,
    ])->run();

    expect($fs->exists($phpPath))->toBeFalse();
    expect($fs->exists($testPath))->toBeFalse();
});
