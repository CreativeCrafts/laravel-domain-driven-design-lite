<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support\Domain;

/**
 * DDD-lite Aggregate Root base.
 * Responsibilities:
 * - Defines an aggregate identifier.
 * - Provides a hook for invariants.
 * - Optionally records in-memory domain events.
 * This class must stay framework-agnostic (no Eloquent, no facades).
 */
abstract class AggregateRoot
{
    /**
     * @var list<object>
     */
    private array $recordedEvents = [];

    /**
     * Return the aggregate's identifier.
     * Implementations typically delegate to a Value Object (e.g. TripId) or
     * a scalar backing field (int|string).
     *
     * @return int|string
     */
    abstract public function id(): int|string;

    /**
     * Enforce all invariants that must always hold for this aggregate.
     * Implementations should call this method:
     * - at the end of any named constructor / factory (e.g. create())
     * - after any state-changing method
     */
    abstract protected function ensureInvariants(): void;

    /**
     * Release and clear all recorded events.
     *
     * @return list<object>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /**
     * Record a domain event. Infra may later dispatch events returned by
     * releaseEvents(), but this class itself stays transport-agnostic.
     */
    protected function recordEvent(object $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
