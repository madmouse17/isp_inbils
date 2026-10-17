<?php

namespace App\Models\Core;

use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An inbound WhatsApp message (a STOP reply) that was already handled for this company. Written by the delivery
 * webhook only, as a keyed hash of the message id: Meta ids embed the sender's number, so the id itself is not kept,
 * and neither are the number or the text.
 *
 * @property int $id
 * @property int $company_id
 * @property string $message_hash
 * @property Carbon $created_at
 */
class WhatsAppInboundReceipt extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use MassPrunable;

    /** Meta redelivers a callback it got no 200 for during seven days; a receipt is of no use after that. */
    private const RETENTION_DAYS = 30;

    protected $table = 'whatsapp_inbound_receipts';

    protected $fillable = ['message_hash'];

    /** Pruning runs without a company context, so the tenant scope must be lifted explicitly. */
    public function prunable(): Builder
    {
        return static::withoutCompany()->where('created_at', '<=', now()->subDays(self::RETENTION_DAYS));
    }
}
