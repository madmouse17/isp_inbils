<?php

namespace Modules\Reporting\Exceptions;

use RuntimeException;

/** Companies in different currencies cannot be added up; the consolidated report refuses instead. */
class ConsolidationRefusedException extends RuntimeException
{
    /** @param list<string> $currencies the distinct currencies of the companies in the report */
    public function __construct(public readonly array $currencies)
    {
        parent::__construct(
            'Consolidation refused: the companies report in different currencies ('.implode(', ', $currencies).'). '
            .'Amounts in different currencies cannot be added up.'
        );
    }
}
