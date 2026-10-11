<?php

namespace Modules\Reporting\Drill;

/**
 * How the rows of a drill-down list tie out to the figure the report shows.
 */
enum Measure: string
{
    /** The figure is the number of rows. */
    case Count = 'count';

    /** The figure is the sum of a column over the rows (minus a second column, for an open balance). */
    case Sum = 'sum';

    /** The figure is a percentage: the on-time rows out of all rows. */
    case Share = 'share';

    /** The figure is an average over the rows: they are listed, but rows alone cannot reproduce it. */
    case Average = 'average';
}
