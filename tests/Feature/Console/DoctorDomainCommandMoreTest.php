<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('fails on stdin-report with parse error and emits parse_error in JSON', function (): void {
    $badJson = '{ not-json';

    $tester = $this->artisan('ddd-lite:doctor:domain', [
        '--json' => true,
        '--stdin-report' => $badJson,
        '--fail-on' => 'any',
    ])->expectsOutputToContain('"parse_error"');

    $exit = $tester->run();

    expect($exit)->toBe(1);
});

it('respects fail-on uncovered and errors for stdin-report', function (): void {
    // With uncovered > 0 and fail-on=uncovered → fail
    $report = json_encode([
        'violations' => 0,
        'uncovered' => 5,
        'warnings' => 0,
        'errors' => 0,
        'allowed' => 0,
    ], JSON_THROW_ON_ERROR);

    $exit1 = $this->artisan('ddd-lite:doctor:domain', [
        '--json' => true,
        '--stdin-report' => $report,
        '--fail-on' => 'uncovered',
    ])->run();

    expect($exit1)->toBe(1);

    // Same report but fail-on=errors → should pass (no errors)
    $exit2 = $this->artisan('ddd-lite:doctor:domain', [
        '--json' => true,
        '--stdin-report' => $report,
        '--fail-on' => 'errors',
    ])->run();

    expect($exit2)->toBe(0);
});

it('coerces string numbers in stdin-report and fails with fail-on any', function (): void {
    $report = json_encode([
        'report' => [
            // Provide string numbers to exercise extractInt coercion and alt keys
            'violationsCount' => '2',
            'uncoveredCount' => '0',
            'warningsCount' => '0',
            'errorsCount' => '0',
            'allowedCount' => '0',
        ],
    ], JSON_THROW_ON_ERROR);

    $exit = $this->artisan('ddd-lite:doctor:domain', [
        '--json' => true,
        '--stdin-report' => $report,
        '--fail-on' => 'any',
    ])->run();

    expect($exit)->toBe(1);
});

it('prints conversion health summary in non-JSON mode when legacy classes are referenced from modules', function (): void {
    // Arrange a fake legacy class under app/Models
    $legacyDir = base_path('app/Models');
    File::ensureDirectoryExists($legacyDir);
    $legacyPath = $legacyDir . '/LegacyThing.php';

    $legacyCode = <<<'PHP'
    <?php
    namespace App\Models;
    class LegacyThing {}
    PHP;
    File::put($legacyPath, $legacyCode);

    // Arrange module file that references the legacy FQN directly
    $moduleDir = base_path('modules/Planner/Domain/Actions');
    File::ensureDirectoryExists($moduleDir);
    $modulePath = $moduleDir . '/UseLegacy.php';

    $moduleCode = <<<'PHP'
    <?php
    namespace Modules\Planner\Domain\Actions;
    // Reference the legacy class FQN so the analyzer can find it
    class UseLegacy {
        public function go(): void
        {
            // simple string reference is enough for the analyzer
            $x = \App\Models\LegacyThing::class;
        }
    }
    PHP;
    File::put($modulePath, $moduleCode);

    // Use stdin-report in text mode to trigger conversion health printing path
    $exit = $this->artisan('ddd-lite:doctor:domain', [
        '--stdin-report' => json_encode(['violations' => 0, 'warnings' => 0], JSON_THROW_ON_ERROR),
        '--fail-on' => 'errors', // ensure success so summary prints
    ])
        ->expectsOutputToContain('[Conversion Health]')
        ->expectsOutputToContain('Legacy app/ classes referenced from modules:')
        // It lists entries by relative path when available
        ->expectsOutputToContain('app/Models/LegacyThing.php')
        ->run();

    expect($exit)->toBe(0);
});

it('warns in non-JSON mode when stdin report cannot be parsed', function (): void {
    $exit = $this->artisan('ddd-lite:doctor:domain', [
        '--stdin-report' => '{bad',
        '--fail-on' => 'violations',
    ])
        ->expectsOutputToContain('Could not parse Deptrac JSON report from stdin:')
        ->run();

    expect($exit)->toBe(1);
});
