<?php

namespace Modules\Ticketing\Http\Requests\Api\V1;

use App\Services\Core\CompanyService;
use Modules\Ticketing\Http\Requests\StoreTicketRequest;
use Modules\Ticketing\Models\TicketCategory;

class StoreCustomerTicketRequest extends StoreTicketRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['source' => 'customer']);

        parent::prepareForValidation();
    }

    /** @return array<string, mixed> */
    public function ticketAttributes(): array
    {
        $data = parent::ticketAttributes();

        if (! array_key_exists('priority', $data) || $data['priority'] === null || $data['priority'] === '') {
            $data['priority'] = TicketCategory::query()
                ->where('company_id', CompanyService::currentId())
                ->whereKey($data['category_id'])
                ->value('default_priority') ?? 'medium';
        }

        return $data;
    }
}
