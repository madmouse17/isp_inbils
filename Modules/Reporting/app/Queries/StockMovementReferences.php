<?php

namespace Modules\Reporting\Queries;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\StockMovement;
use Modules\SPK\Models\WorkOrderItem;

/**
 * What the ledger rows of a Stock Card page point at: the SPK item or inventory request that
 * issued or received the stock. Resolved for a whole page in one query per type; a link is given
 * only when the actor may open the target, otherwise the row shows the label alone.
 */
final class StockMovementReferences
{
    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return array<int, array{label: string, url: string|null}> keyed by movement id
     */
    public static function resolve(Collection $movements, User $actor): array
    {
        $workOrderItemType = (new WorkOrderItem())->getMorphClass();
        $requestItemType = (new InventoryRequestItem())->getMorphClass();

        $workOrderItems = self::items($movements, $workOrderItemType, fn (array $ids) => WorkOrderItem::query()
            ->whereIn('id', $ids)->with('workOrder:id,company_id,code')->get()->keyBy('id'));
        $requestItems = self::items($movements, $requestItemType, fn (array $ids) => InventoryRequestItem::query()
            ->whereIn('id', $ids)->with('inventoryRequest:id,company_id,code,requested_by')->get()->keyBy('id'));

        $references = [];
        foreach ($movements as $movement) {
            if ($movement->reference_type === null) {
                continue;
            }

            $references[$movement->id] = match ($movement->reference_type) {
                $workOrderItemType => self::target(
                    $workOrderItems->get($movement->reference_id)?->workOrder,
                    'SPK',
                    'admin.spk.show',
                    $actor,
                ),
                $requestItemType => self::target(
                    $requestItems->get($movement->reference_id)?->inventoryRequest,
                    'Request',
                    'admin.inventory.requests.show',
                    $actor,
                ),
                default => ['label' => class_basename($movement->reference_type).' #'.$movement->reference_id, 'url' => null],
            };
        }

        return $references;
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @param  callable(array<int, int>): Collection<int, mixed>  $load
     * @return Collection<int, mixed>
     */
    private static function items(Collection $movements, string $type, callable $load): Collection
    {
        $ids = $movements->where('reference_type', $type)->pluck('reference_id')->unique()->values()->all();

        return $ids === [] ? collect() : $load($ids);
    }

    /** @return array{label: string, url: string|null} */
    private static function target(?object $target, string $noun, string $route, User $actor): array
    {
        if ($target === null) {
            return ['label' => $noun, 'url' => null];
        }

        return [
            'label' => $noun.' '.$target->code,
            'url' => $actor->can('view', $target) ? route($route, $target->getKey(), false) : null,
        ];
    }
}
