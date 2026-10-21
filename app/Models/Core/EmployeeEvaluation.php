<?php

namespace App\Models\Core;

use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Database\Factories\EmployeeEvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmployeeEvaluation extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    protected static string $factory = EmployeeEvaluationFactory::class;

    protected $fillable = [
        'employee_id', 'reference_type', 'reference_id', 'score',
        'customer_rating', 'first_response_minutes', 'resolution_minutes',
        'comment', 'evaluator_id', 'evaluated_at',
    ];

    protected $casts = [
        'score' => 'decimal:1',
        'customer_rating' => 'decimal:1',
        'first_response_minutes' => 'integer',
        'resolution_minutes' => 'integer',
        'evaluated_at' => 'datetime',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Evaluasi';
    }

    public static function lifecycleFilters(): array
    {
        return [];
    }

    /** An evaluation keeps its own soft delete: it can be deleted and restored, nothing else points at it. */
    public static function lifecycleReferences(): ?array
    {
        return null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    /**
     * What an evaluation can be about. The column holds the short name (`Ticket`), not a class name, so there is
     * no morph relation; `loadReferences` sets the record as the relation `reference` instead.
     *
     * @return array<string, class-string<Model>>
     */
    public static function referenceModels(): array
    {
        return ['WorkOrder' => WorkOrder::class, 'Ticket' => Ticket::class];
    }

    /**
     * Loads the record each evaluation is about (a deleted one too) in one query per type.
     *
     * @param  Collection<int, self>  $evaluations
     */
    public static function loadReferences(Collection $evaluations): void
    {
        foreach (self::referenceModels() as $type => $model) {
            $matching = $evaluations->where('reference_type', $type);
            $records = $model::query()->withTrashed()->whereKey($matching->pluck('reference_id')->unique())->get(['id', 'public_id', 'code', 'title'])->keyBy('id');

            $matching->each(fn (self $evaluation) => $evaluation->setRelation('reference', $records->get($evaluation->reference_id)));
        }
    }

    public function referencePublicId(): ?string
    {
        if (! $this->relationLoaded('reference')) {
            self::loadReferences(new Collection([$this]));
        }

        return $this->getRelation('reference')?->public_id;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employee_evaluation')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
