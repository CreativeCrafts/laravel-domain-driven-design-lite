<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support\Doctor;

final class Violation
{
    public function __construct(
        public string $rule,
        public string $file,
        public string $message,
        public string $severity = 'error',
        public bool $fixable = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'file' => $this->file,
            'message' => $this->message,
            'severity' => $this->severity,
            'fixable' => $this->fixable,
        ];
    }
}
