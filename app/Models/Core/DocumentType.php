<?php

namespace App\Models\Core;

use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class DocumentType extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    protected static string $factory = DocumentTypeFactory::class;

    protected $fillable = [
        'company_id', 'name', 'code', 'applies_to', 'is_required', 'expiry_days', 'is_active',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'expiry_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Jenis dokumen';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    /** An uploaded document names its type by id in its custom properties (DocumentService::upload). */
    public static function lifecycleReferences(): ?array
    {
        return ['dokumen' => ['media.custom_properties->document_type_id']];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('document_type')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
