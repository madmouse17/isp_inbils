<?php

namespace App\Models\Core;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NumberSequence extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected static string $factory = \Database\Factories\NumberSequenceFactory::class;

    protected $fillable = [
        'company_id', 'entity_type', 'prefix', 'next_number', 'padding', 'year_suffix',
    ];

    protected $casts = [
        'next_number' => 'integer',
        'padding' => 'integer',
        'year_suffix' => 'boolean',
    ];
}
