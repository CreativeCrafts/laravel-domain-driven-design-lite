<?php

declare(strict_types=1);

it('delegates boundaries to doctor:domain with stdin report', function (): void {
    $report = json_encode([
        'summary' => [
            'violations' => 0,
            'uncovered' => 0,
            'allowed' => 0,
            'warnings' => 0,
            'errors' => 0,
        ],
    ], JSON_THROW_ON_ERROR);

    $exit = $this->artisan('ddd-lite:boundaries', [
        '--stdin-report' => $report,
        '--json' => true,
    ])->run();

    expect($exit)->toBe(0);
});
