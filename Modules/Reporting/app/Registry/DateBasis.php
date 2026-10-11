<?php

namespace Modules\Reporting\Registry;

/**
 * How a figure reacts to the From/To filter of its report.
 */
enum DateBasis: string
{
    /** Reflects current records; the From/To filter does not change it. */
    case Snapshot = 'snapshot';

    /** Limited to records whose date column falls inside the From/To range. */
    case Period = 'period';
}
