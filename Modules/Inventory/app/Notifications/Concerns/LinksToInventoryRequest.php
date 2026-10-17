<?php

namespace Modules\Inventory\Notifications\Concerns;

use Modules\Inventory\Models\InventoryRequest;

/**
 * The request a mail or a bell row is about. The notification carries the integer id (the queue payload is
 * internal); only the public id may leave the server, in the link and in the bell data.
 *
 * @property int $requestId
 */
trait LinksToInventoryRequest
{
    private function requestPublicId(): ?string
    {
        return InventoryRequest::withoutGlobalScopes()->whereKey($this->requestId)->value('public_id');
    }

    private function requestUrl(): string
    {
        $publicId = $this->requestPublicId();

        return $publicId === null ? route('admin.inventory.requests.index') : route('admin.inventory.requests.show', $publicId);
    }
}
