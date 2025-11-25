<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('exports conversion plan as json when requested', function (): void {
    $fs = new Filesystem();

    // Arrange: a simple controller to be discovered as a move candidate
    $fs->ensureDirectoryExists(base_path('app/Http/Controllers'));
    $fs->put(
        base_path('app/Http/Controllers/ExportPlanController.php'),
        "<?php\nnamespace App\\Http\\Controllers;\nclass ExportPlanController {}\n"
    );

    $exportPath = storage_path('app/ddd-lite_scaffold/plan_test.json');
    if ($fs->exists($exportPath)) {
        $fs->delete($exportPath);
    }

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => 'Planner',
        '--plan-moves' => true,
        '--export-plan' => $exportPath,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($exportPath))->toBeTrue();

    $data = json_decode((string)$fs->get($exportPath), true);

    expect($data)->toBeArray()
        ->and($data)->not()->toBeEmpty()
        ->and($data[0])->toHaveKeys([
            'from_abs',
            'to_abs',
            'from_rel',
            'to_rel',
            'from_namespace',
            'to_namespace',
            'kind',
        ]);
});

it('does not write export plan file in dry-run mode', function (): void {
    $fs = new Filesystem();

    $fs->ensureDirectoryExists(base_path('app/Http/Controllers'));
    $fs->put(
        base_path('app/Http/Controllers/DryRunExportController.php'),
        "<?php\nnamespace App\\Http\\Controllers;\nclass DryRunExportController {}\n"
    );

    $exportPath = storage_path('app/ddd-lite_scaffold/plan_test_dry_run.json');
    if ($fs->exists($exportPath)) {
        $fs->delete($exportPath);
    }

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => 'Planner',
        '--plan-moves' => true,
        '--export-plan' => $exportPath,
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($exportPath))->toBeFalse();
});
