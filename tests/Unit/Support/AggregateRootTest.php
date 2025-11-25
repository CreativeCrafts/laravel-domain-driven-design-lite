<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Domain\AggregateRoot;

it('records and releases events and exposes id', function () {
    $a = new class (123) extends AggregateRoot {
        public function __construct(private int|string $id)
        {
            // simulate event during construction
            $this->recordEvent((object)['type' => 'created']);
            $this->ensureInvariants();
        }

        public function id(): int|string
        {
            return $this->id;
        }

        protected function ensureInvariants(): void
        {
            if ($this->id === '') {
                throw new InvalidArgumentException('id cannot be empty');
            }
        }
    };

    $events = $a->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeObject();

    // second release should be empty
    expect($a->releaseEvents())->toBe([])
        ->and($a->id())->toBe(123);
});
