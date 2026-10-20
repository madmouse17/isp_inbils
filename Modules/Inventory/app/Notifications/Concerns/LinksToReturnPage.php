<?php

namespace Modules\Inventory\Notifications\Concerns;

use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\SPK\Models\WorkOrder;

/**
 * What a return bell row names. A material return has no page of its own (`admin.inventory.returns.show` answers
 * JSON); the request it belongs to shows it in its detail page.
 *
 * The notification carries integer ids (the queue payload is internal). The bell reads the records here: only a public
 * id may leave the server.
 *
 * @property int $caseId
 * @property int|null $workOrderId
 * @property int|null $companyId
 * @property int|null $inventoryRequestId
 *
 * @phpstan-type Records array{case: ?MaterialReturnCase, work_order: ?WorkOrder, request: ?InventoryRequest}
 */
trait LinksToReturnPage
{
    /** @return Records */
    private function returnRecords(): array
    {
        return [
            'case' => MaterialReturnCase::withoutGlobalScopes()->find($this->caseId, ['id', 'public_id']),
            'work_order' => ($this->workOrderId ?? null) === null ? null : WorkOrder::withoutGlobalScopes()->find($this->workOrderId, ['id', 'public_id']),
            'request' => $this->inventoryRequestId === null ? null : InventoryRequest::withoutGlobalScopes()->find($this->inventoryRequestId, ['id', 'public_id']),
        ];
    }

    /**
     * @param  Records  $records
     * @return array<string, mixed>
     */
    private function returnData(string $type, array $records): array
    {
        return [
            'type' => $type,
            'case_id' => $records['case']?->public_id,
            'work_order_id' => $records['work_order']?->public_id,
            'company_id' => $this->companyId,
            'inventory_request_id' => $records['request']?->public_id,
        ];
    }
}
