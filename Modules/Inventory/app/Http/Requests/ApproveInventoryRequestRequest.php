<?php

namespace Modules\Inventory\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;

class ApproveInventoryRequestRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return [
            'warehouse_location_id' => Location::class,
            'items.*.id' => InventoryRequestItem::class,
            'items.*.source_location_id' => Location::class,
        ];
    }

    public function authorize(): bool
    {
        $request = $this->route('inventory_request');

        return $this->user()?->can('approve', $request) ?? false;
    }

    protected function failedAuthorization(): void
    {
        if ($this->header('X-Inertia')) {
            throw new HttpResponseException(
                back()->withErrors(['error' => 'Permintaan barang tidak dapat disetujui karena status telah berubah atau Anda tidak berwenang.'])
            );
        }

        parent::failedAuthorization();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'approval_note' => ['nullable', 'string', 'max:1000'],
            'warehouse_location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity_approved' => ['required', 'numeric', 'gt:0'],
            'items.*.source_location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var InventoryRequest|null $req */
            $req = $this->route('inventory_request');
            if (! $req) {
                return;
            }

            $itemsData = $this->input('items', []);
            if (empty($itemsData)) {
                return;
            }

            $dbItems = $req->items()->get()->keyBy('id');

            foreach ($itemsData as $index => $itemInput) {
                $itemId = $itemInput['id'] ?? null;
                $approvedQty = (float) ($itemInput['quantity_approved'] ?? 0);

                if ($itemId && $dbItems->has($itemId)) {
                    /** @var InventoryRequestItem $dbItem */
                    $dbItem = $dbItems->get($itemId);
                    $requestedQty = (float) $dbItem->quantity_requested;
                    if ($approvedQty > $requestedQty) {
                        $validator->errors()->add(
                            "items.{$index}.quantity_approved",
                            "Jumlah barang yang disetujui ({$approvedQty}) tidak boleh melampaui jumlah yang diminta ({$requestedQty})."
                        );
                    }
                }
            }
        });
    }
}
