<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

function convertMovesUniqueSuffix(): string {
    return strtoupper(substr(md5(uniqid('', true)), 0, 8));
}

beforeEach(function (): void {
    $fs = new Filesystem();

    // Seed a legacy controller as a move candidate (source owned by this test file)
    $fs->ensureDirectoryExists(base_path('app/Http/Controllers'));
    $fs->put(
        base_path('app/Http/Controllers/WorldController.php'),
        <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class WorldController {}
PHP
    );

    // Reduce chance of extra prompts from another test's candidate
    $sample = base_path('app/Http/Controllers/SampleController.php');
    if ($fs->exists($sample)) {
        $fs->delete($sample);
    }
});

it('apply-moves with --all performs moves non-interactively', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerConvertApplyMoves' . convertMovesUniqueSuffix();
    $moduleRoot = base_path('modules/' . $module);
    if ($fs->exists($moduleRoot)) {
        $fs->deleteDirectory($moduleRoot);
    }

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => $module,
        '--apply-moves' => true,
        '--all' => true,
    ])->run();

    expect($exit)->toBe(0);

    $moved = base_path('modules/' . $module . '/App/Http/Controllers/WorldController.php');
    expect($fs->exists($moved))->toBeTrue();

    $code = (string)$fs->get($moved);
    expect($code)->toContain('namespace Modules\\' . $module . '\\App\\Http\\Controllers;');
});

it('apply-moves with --review asks per item and applies only approved ones', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerConvertApplyMoves' . convertMovesUniqueSuffix();
    $moduleRoot = base_path('modules/' . $module);
    if ($fs->exists($moduleRoot)) {
        $fs->deleteDirectory($moduleRoot);
    }

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => $module,
        '--apply-moves' => true,
        '--all' => true,
    ])->run();

    expect($exit)->toBe(0);

    $moved = base_path('modules/' . $module . '/App/Http/Controllers/WorldController.php');
    expect($fs->exists($moved))->toBeTrue();

    $code = (string)$fs->get($moved);
    expect($code)->toContain('namespace Modules\\' . $module . '\\App\\Http\\Controllers;');
});

it('apply-moves dry-run prints plan and does not write', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerConvertApplyMoves' . convertMovesUniqueSuffix();
    $moduleRoot = base_path('modules/' . $module);
    if ($fs->exists($moduleRoot)) {
        $fs->deleteDirectory($moduleRoot);
    }

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => $module,
        '--apply-moves' => true,
        '--all' => true,
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0);

    $moved = base_path('modules/' . $module . '/App/Http/Controllers/WorldController.php');
    expect($fs->exists($moved))->toBeFalse();
});

it('fails on destination exists without --force (guard remains intact)', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerConvertApplyMoves' . convertMovesUniqueSuffix();

    // Prime a destination file to trigger the "destination exists" branch
    $dest = base_path('modules/' . $module . '/App/Http/Controllers/WorldController.php');
    $fs->ensureDirectoryExists(dirname($dest));
    $fs->put($dest, "<?php\n// pre-existing\n");

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => $module,
        '--apply-moves' => true,
        '--all' => true,
    ])->run();

    // Command should fail (exit 1) because --force was not provided
    expect($exit)->toBe(1)
        ->and($fs->exists(base_path('app/Http/Controllers/WorldController.php')))->toBeTrue();
});

it('overwrites destination with --force', function (): void {
    $fs = new Filesystem();
    $module = 'PlannerConvertApplyMoves' . convertMovesUniqueSuffix();

    // Prime a destination file to be overwritten
    $dest = base_path('modules/' . $module . '/App/Http/Controllers/WorldController.php');
    $fs->ensureDirectoryExists(dirname($dest));
    $fs->put($dest, "<?php\n// pre-existing\n");

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => $module,
        '--apply-moves' => true,
        '--all' => true,
        '--force' => true, // allow overwrite
    ])->run();

    expect($exit)->toBe(0);

    // Source moved, destination contains rewritten namespace, source removed
    $code = (string)$fs->get($dest);
    expect($code)->toContain('namespace Modules\\' . $module . '\\App\\Http\\Controllers;')
        ->and($fs->exists(base_path('app/Http/Controllers/WorldController.php')))->toBeFalse();
});
