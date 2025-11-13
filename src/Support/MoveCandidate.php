<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

final readonly class MoveCandidate
{
    public function __construct(
        public string $fromAbs,
        public string $toAbs,
        public string $fromRel,
        public string $toRel,
        public string $fromNamespace,
        public string $toNamespace,
        public string $reason
    ) {
    }
}
