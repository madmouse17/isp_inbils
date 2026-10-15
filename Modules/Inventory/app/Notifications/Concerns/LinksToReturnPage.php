<?php

namespace Modules\Inventory\Notifications\Concerns;

/**
 * Where a return mail sends the reader. A material return has no page of its own (`admin.inventory.returns.show` answers
 * JSON); the request it belongs to shows it in its detail page, and a case without a request is listed in the returns tab.
 *
 * @property int|null $inventoryRequestId
 */
trait LinksToReturnPage
{
    private function returnPageUrl(): string
    {
        return $this->inventoryRequestId
            ? route('admin.inventory.requests.show', $this->inventoryRequestId)
            : route('admin.inventory.requests.index', ['tab' => 'returns']);
    }
}
