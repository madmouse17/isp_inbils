<?php

namespace Modules\SPK\Models;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WorkOrderEvidence extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'work_order_id', 'type', 'file_path', 'original_name',
        'mime_type', 'size_bytes', 'caption', 'uploaded_by', 'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
