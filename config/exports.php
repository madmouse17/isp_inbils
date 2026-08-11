<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Export row cap
    |--------------------------------------------------------------------------
    |
    | Hard ceiling for CSV/PDF list exports. Controllers may pass a lower
    | per-export override but cannot exceed this without changing config.
    |
    */
    'max_rows' => (int) env('EXPORTS_MAX_ROWS', 5000),
];
