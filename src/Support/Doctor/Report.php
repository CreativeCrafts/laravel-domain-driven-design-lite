<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support\Doctor;

final class Report
{
    /**
     * @param array<int, Violation> $violations
     */
    public function __construct(
        public bool $ok,
        public int $totalAll,
        public int $totalErrors,
        public int $totalWarnings,
        public array $violations,
        public string $schema,
        public string $version,
    ) {
    }

    public function totalsAll(): int
    {
        return $this->totalAll;
    }

    public function totalsErrors(): int
    {
        return $this->totalErrors;
    }

    public function totalsWarnings(): int
    {
        return $this->totalWarnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'totals' => [
                'all' => $this->totalAll,
                'errors' => $this->totalErrors,
                'warnings' => $this->totalWarnings,
            ],
            'violations' => array_map(
                static fn (Violation $v): array => $v->toArray(),
                $this->violations
            ),
            'schema' => $this->schema,
            'version' => $this->version,
        ];
    }
}
