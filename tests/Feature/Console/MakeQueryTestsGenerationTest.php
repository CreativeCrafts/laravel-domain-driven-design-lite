<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('generates tests for query, query builder, and aggregator by default', function (): void {
    $fs = new Filesystem();
    $module = 'QueryTestGen';

    $fs->deleteDirectory(base_path("modules/{$module}"));
    $fs->ensureDirectoryExists(base_path("modules/{$module}/Domain"));

    $this->artisan('ddd-lite:make:query', [
        'module' => $module,
        'name' => 'TripIndexQuery',
    ])->run();

    $this->artisan('ddd-lite:make:query-builder', [
        'module' => $module,
        'name' => 'Trip',
    ])->run();

    $this->artisan('ddd-lite:make:aggregator', [
        'module' => $module,
        'name' => 'TripIndex',
    ])->run();

    expect($fs->exists(base_path("modules/{$module}/tests/Unit/Domain/Queries/TripIndexQueryTest.php")))->toBeTrue()
        ->and($fs->exists(base_path("modules/{$module}/tests/Unit/Domain/Builders/TripQueryBuilderTest.php")))->toBeTrue()
        ->and($fs->exists(base_path("modules/{$module}/tests/Unit/Domain/Aggregators/TripIndexAggregatorTest.php")))->toBeTrue();
});
