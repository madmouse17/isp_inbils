<?php

namespace Modules\Reporting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One acquirer row of the Sales KPI as computed by SalesKpiQuery (an array, not a model).
 *
 * @mixin array<string, mixed>
 */
class SalesKpiRowResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'name' => $this['name'],
            'is_active' => $this['is_active'],
            'customers_acquired' => $this['customers_acquired'],
            'subscriptions_activated' => $this['subscriptions_activated'],
            'active_mrr' => $this['active_mrr'],
            'invoiced' => $this['invoiced'],
            'collected' => $this['collected'],
            'outstanding' => $this['outstanding'],
            'collection_rate' => $this['collection_rate'],
        ];
    }
}
