<?php

namespace App\Models\Core;

use App\Support\NumberSequences\ResetPeriod;
use App\Traits\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\NumberSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $company_id
 * @property string $entity_type
 * @property string $prefix
 * @property string $template
 * @property int $next_number
 * @property ResetPeriod $reset_period
 * @property string|null $period_key
 * @property bool $allow_manual
 * @property CarbonInterface|null $updated_at
 * @property-read Company|null $company
 */
class NumberSequence extends Model
{
    use BelongsToCompany;
    use HasFactory;

    /** Largest value the unsigned integer column `next_number` holds. */
    public const MAX_NUMBER = 4_294_967_295;

    protected static string $factory = NumberSequenceFactory::class;

    protected $fillable = [
        'company_id', 'entity_type', 'prefix', 'template', 'next_number', 'reset_period', 'period_key', 'allow_manual',
    ];

    protected $attributes = [
        'reset_period' => 'never',
        'allow_manual' => false,
    ];

    protected $casts = [
        'next_number' => 'integer',
        'reset_period' => ResetPeriod::class,
        'allow_manual' => 'boolean',
    ];
}
