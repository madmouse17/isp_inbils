<?php

namespace Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
class ReceivableResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'customer_id' => $this['customer_id'],
            'customer' => $this['customer'],
            'current' => $this['current'],
            'b1_30' => $this['b1_30'],
            'b31_60' => $this['b31_60'],
            'b61_90' => $this['b61_90'],
            'b90_plus' => $this['b90_plus'],
            'total' => $this['total'],
            'invoice_count' => $this['invoice_count'],
            'subscriptions' => $this['subscriptions'],
        ];
    }
}
