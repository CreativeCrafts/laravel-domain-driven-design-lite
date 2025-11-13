<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support\Doctor;

final class JsonReporter
{
    /**
     * @param array<int, Violation> $violations
     */
    public function build(array $violations): Report
    {
        $total = count($violations);
        $errors = 0;
        $warnings = 0;

        foreach ($violations as $v) {
            if ($v->severity === 'warning') {
                $warnings++;
            } else {
                $errors++;
            }
        }

        return new Report(
            ok: $total === 0,
            totalAll: $total,
            totalErrors: $errors,
            totalWarnings: $warnings,
            violations: $violations,
            schema: 'https://creativecrafts.dev/ddd-lite/doctor.schema.json',
            version: '1.0',
        );
    }
}
