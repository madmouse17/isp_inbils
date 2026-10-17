<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $material_return_case_id
 * @property ?string $from_status
 * @property string $to_status
 * @property string $action
 * @property int $actor_id
 * @property ?string $reason
 * @property ?array<string, mixed> $payload
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read MaterialReturnCase $case
 * @property-read User $actor
 */
class MaterialReturnEvent extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $table = 'material_return_events';

    protected $fillable = [
        'company_id',
        'material_return_case_id',
        'from_status',
        'to_status',
        'action',
        'actor_id',
        'reason',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(MaterialReturnCase::class, 'material_return_case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
