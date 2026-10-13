<?php

namespace Tests\Traits;

use App\Models\Core\NumberSequence;
use App\Services\Core\NumberSequenceService;

trait AllowsManualCodes
{
    /** Turns manual code input on (or off) for the given entity types, so a test can post fixed codes. */
    protected function setManualCodes(int $companyId, bool $allowed, string ...$entityTypes): void
    {
        NumberSequenceService::ensureAll($companyId);

        NumberSequence::withoutCompany()
            ->where('company_id', $companyId)
            ->whereIn('entity_type', $entityTypes)
            ->update(['allow_manual' => $allowed]);
    }
}
