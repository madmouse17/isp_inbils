<?php

namespace App\Models\Core;

use App\Traits\BelongsToCompany;
use Database\Factories\WhatsAppMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One WhatsApp template message, or the reason one was not sent. Written by WhatsAppChannel and the
 * delivery webhook only. Holds ids, a keyed hash and the last four digits of the phone, never the full
 * number, the rendered text or the template parameters.
 *
 * @property int $id
 * @property int $company_id
 * @property string $recipient_type customer|user
 * @property int $recipient_id
 * @property string $event
 * @property ?string $subject_type
 * @property ?int $subject_id
 * @property string $template
 * @property string $language
 * @property string $dedupe_key
 * @property string $status
 * @property ?string $skip_reason
 * @property ?string $error_code
 * @property ?string $provider_message_id
 * @property ?string $recipient_hash
 * @property ?string $recipient_last4
 * @property ?bool $billable
 * @property ?string $pricing_category
 * @property ?Carbon $sent_at
 * @property ?Carbon $delivered_at
 * @property ?Carbon $read_at
 * @property ?Carbon $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WhatsAppMessage extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use MassPrunable;

    public const STATUSES = ['queued', 'sent', 'delivered', 'read', 'failed', 'skipped'];

    public const SKIP_REASONS = ['disabled', 'event_disabled', 'no_consent', 'opted_out', 'invalid_phone', 'budget_exceeded'];

    /** Log rows older than this are pruned (model:prune, scheduled daily). */
    private const RETENTION_MONTHS = 12;

    protected static string $factory = WhatsAppMessageFactory::class;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'event',
        'subject_type',
        'subject_id',
        'template',
        'language',
        'dedupe_key',
        'status',
        'skip_reason',
        'error_code',
        'provider_message_id',
        'recipient_hash',
        'recipient_last4',
        'billable',
        'pricing_category',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
    ];

    protected $casts = [
        'recipient_id' => 'integer',
        'subject_id' => 'integer',
        'billable' => 'boolean',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /** Pruning runs without a company context, so the tenant scope must be lifted explicitly. */
    public function prunable(): Builder
    {
        return static::withoutCompany()->where('created_at', '<=', now()->subMonths(self::RETENTION_MONTHS));
    }
}
