<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Doctor\DoctorCiJson;

it('builds CI JSON with sorted normalized violations and correct totals/status', function (): void {
    $issues = [
        [
            'severity' => 'warning',
            'type' => 'Deprecation',
            'message' => 'Thing is deprecated',
            'file' => 'src/B.php',
            'line' => '12',
            'column' => 3,
            'doctorKey' => 'deps',
            'suggestion' => 'Use new thing',
            'fixable' => false,
        ],
        [
            'severity' => 'error',
            'type' => 'Syntax',
            'message' => 'Missing semicolon',
            'file' => 'src/A.php',
            'line' => 5,
            'column' => '7',
            'doctorKey' => null,
            'suggestion' => null,
            'fixable' => true,
        ],
        [
            // malformed values should normalize to defaults
            'message' => 'Unknown',
            'file' => 'src/C.php',
            'line' => 'not-a-number',
            'column' => null,
        ],
    ];

    $json = DoctorCiJson::build($issues);

    expect($json['version'])->toBe('1.0.0')
        ->and($json['status'])->toBe('fail') // because at least one error
        ->and($json['totals']['errors'])->toBe(1)
        ->and($json['totals']['warnings'])->toBe(2)
        ->and($json['totals']['fixable'])->toBe(1)
        ->and($json['metadata'])->toHaveKeys(['packageVersion', 'ci'])
        ->and(is_string($json['generatedAt']))->toBeTrue();

    // Sorted by file, line, code
    $violations = $json['violations'];
    expect($violations)->toBeArray()
        ->and($violations[0]['file'])->toBe('src/A.php')
        ->and($violations[0]['line'])->toBe(5)
        ->and($violations[0]['type'] ?? $violations[0]['code'])->toBeTruthy();
});
