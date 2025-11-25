<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Doctor\JsonReporter;
use CreativeCrafts\DomainDrivenDesignLite\Support\Doctor\Violation;

it('builds a report with correct totals and ok flag', function () {
    $violations = [
        new Violation(rule: 'Architecture', file: 'app/Models/User.php', message: 'Bad coupling', severity: 'error'),
        new Violation(rule: 'Style', file: 'app/Http/Controllers/Home.php', message: 'Nit', severity: 'warning'),
    ];

    $report = (new JsonReporter())->build($violations);

    expect($report->ok)->toBeFalse()
        ->and($report->totalsAll())->toBe(2)
        ->and($report->totalsErrors())->toBe(1)
        ->and($report->totalsWarnings())->toBe(1)
        ->and($report->toArray()['violations'][0]['rule'])->toBe('Architecture');
});
