<?php

namespace Modules\Ticketing\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ticketing\Models\TicketCategory;

/**
 * "What would this ticket be held to?", asked by the create form before anything is saved. The category, and the
 * subscription when one is picked, belong to the person's company; a customer account may only name its own subscription.
 * The priority is the one on the form, since priority urgent halves both targets.
 */
class SlaPreviewTicketRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['category_id' => TicketCategory::class, 'subscription_id' => ServiceSubscription::class];
    }

    public function authorize(): bool
    {
        // As StoreTicketRequest: a customer login that is not linked to a customer record files nothing.
        if ($this->isCustomerUser() && $this->ownCustomerId() === null) {
            return false;
        }

        return $this->user()?->can('ticket.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();
        $subscription = Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId);

        if ($this->isCustomerUser()) {
            $subscription->where('customer_id', $this->ownCustomerId());
        }

        return [
            'category_id' => ['required', Rule::exists('ticket_categories', 'id')->where('company_id', $companyId)],
            'subscription_id' => ['nullable', $subscription],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
        ];
    }

    private function isCustomerUser(): bool
    {
        return $this->user()?->can('portal.access') ?? false;
    }

    private function ownCustomerId(): ?int
    {
        return $this->user()?->customer?->id;
    }
}
