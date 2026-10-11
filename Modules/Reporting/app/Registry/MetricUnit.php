<?php

namespace Modules\Reporting\Registry;

/**
 * What a reported figure measures; drives how the UI and the dictionary label it.
 */
enum MetricUnit: string
{
    case Currency = 'currency';
    case Count = 'count';
    case Percent = 'percent';
    case Minutes = 'minutes';
    case Rating = 'rating';
    case Quantity = 'quantity';
    case Breakdown = 'breakdown';
    case Records = 'records';
}
