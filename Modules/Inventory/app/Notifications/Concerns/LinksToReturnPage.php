<?php

namespace Modules\Inventory\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\SPK\Models\WorkOrder;

/**
 * What a return mail names and where it sends the reader. A material return has no page of its own
 * (`admin.inventory.returns.show` answers JSON); the request it belongs to shows it in its detail page, and a case
 * without a request is listed in the returns tab.
 *
 * The notification carries integer ids (the queue payload is internal). Mail and bell read the records here: only a
 * public id or a code may leave the server, so the request code names the case.
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
            'work_order' => ($this->workOrderId ?? null) === null ? null : WorkOrder::withoutGlobalScopes()->find($this->workOrderId, ['id', 'public_id', 'code']),
            'request' => $this->inventoryRequestId === null ? null : InventoryRequest::withoutGlobalScopes()->find($this->inventoryRequestId, ['id', 'public_id', 'code']),
        ];
    }

    /** @param  Records  $records */
    private function returnSubject(string $title, array $records): string
    {
        return $title.($records['request'] === null ? '' : ': '.$records['request']->code);
    }

    /** @param  Records  $records */
    private function returnReference(MailMessage $mail, array $records): MailMessage
    {
        if ($records['request'] !== null) {
            $mail->line('Permintaan Barang: '.$records['request']->code);
        }

        if ($records['work_order'] !== null) {
            $mail->line('Terkait SPK '.$records['work_order']->code);
        }

        return $mail;
    }

    /** @param  Records  $records */
    private function returnPageUrl(array $records): string
    {
        return $records['request'] !== null
            ? route('admin.inventory.requests.show', $records['request'])
            : route('admin.inventory.requests.index', ['tab' => 'returns']);
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
