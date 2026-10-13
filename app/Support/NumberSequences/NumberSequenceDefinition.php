<?php

namespace App\Support\NumberSequences;

/** What a module or the core says about one numbered entity: where its codes live and how a new company starts. */
final readonly class NumberSequenceDefinition
{
    public function __construct(
        public string $entityType,
        public string $label,
        public string $table,
        public string $column,
        public int $maxLength,
        public string $defaultPrefix,
        public string $defaultTemplate,
        public ResetPeriod $defaultReset = ResetPeriod::Never,
        public bool $defaultAllowManual = false,
    ) {}
}
