<?php

declare(strict_types=1);

it('emits JSON summary from stdin report and exits 0 when no violations', function (): void {
    $report = json_encode(['violations' => 0, 'warnings' => 1], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exit = $this->artisan('ddd-lite:doctor:domain', [
        '--json' => true,
        '--stdin-report' => $report,
        '--fail-on' => 'violations',
    ])->run();

    expect($exit)->toBe(0);
});

it('emits JSON summary and exits non-zero when violations > 0 and fail-on=violations', function (): void {
    $report = json_encode(['violations' => 3, 'warnings' => 0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exit = $this->artisan('ddd-lite:doctor:domain', [
        '--json' => true,
        '--stdin-report' => $report,
        '--fail-on' => 'violations',
    ])->run();

    expect($exit)->toBe(1);
});

it('fails fast with friendly JSON when deptrac bin is missing', function (): void {
    $exit = $this->artisan('ddd-lite:doctor:domain', [
        '--json' => true,
        '--bin' => 'vendor/bin/does-not-exist',
        '--config' => 'deptrac.yaml',
    ])->run();

    expect($exit)->toBe(1);
});
