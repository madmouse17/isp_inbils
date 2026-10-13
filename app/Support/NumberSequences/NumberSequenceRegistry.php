<?php

namespace App\Support\NumberSequences;

use InvalidArgumentException;

/** The fixed list of numbered entities. Core and modules register theirs from their service providers. */
final class NumberSequenceRegistry
{
    /** @var array<string, NumberSequenceDefinition> */
    private array $definitions = [];

    public function register(NumberSequenceDefinition $definition): void
    {
        $this->definitions[$definition->entityType] = $definition;
    }

    public function get(string $entityType): NumberSequenceDefinition
    {
        return $this->definitions[$entityType]
            ?? throw new InvalidArgumentException("No number sequence is registered for [{$entityType}].");
    }

    /** @return array<string, NumberSequenceDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
}
