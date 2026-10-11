<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;

/** One step of a work order at a time, done by the person whose job it is. SPKs are found by registry key. */
final class SpkFlow
{
    /** A 1x1 grey PNG: the evidence photo of every demo job. */
    private const EVIDENCE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public static function find(DemoContext $c, string $key): WorkOrder
    {
        return WorkOrder::query()->findOrFail($c->get('spk', $key));
    }

    public static function assign(DemoContext $c, string $key, string $technician, string $by = 'manager'): void
    {
        $c->as($by, function (User $assigner) use ($c, $key, $technician): void {
            $workOrder = self::find($c, $key);

            SpkService::assign(
                $workOrder,
                $c->user($technician)->id,
                $assigner->id,
                $workOrder->assigned_to === null ? null : (int) $workOrder->assigned_to,
                $workOrder->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            );

            $workOrder->refresh()->update(['scheduled_date' => now()->addDay()->toDateString()]);
        });
    }

    public static function start(DemoContext $c, string $key, string $technician): void
    {
        $c->as($technician, fn () => SpkService::start(self::find($c, $key)));
    }

    /**
     * The technician records what was used (a serialised ONT included when $ont names the POP and
     * preferred model), uploads the evidence photo and submits the job for review.
     *
     * @param  array<string, int|float>  $materials  sku => quantity used
     * @param  array{0: string, 1: string}|null  $ont  [POP key, preferred ONT sku]
     */
    public static function finish(DemoContext $c, string $key, string $technician, array $materials, ?array $ont = null): void
    {
        $c->as($technician, function (User $tech) use ($c, $key, $materials, $ont): void {
            $workOrder = self::find($c, $key);

            if ($ont !== null) {
                $asset = NetworkDemo::pickOnt($c, $ont[0], $ont[1]);
                SpkService::addItem($workOrder, [
                    'product_id' => $asset->product_id,
                    'network_asset_id' => $asset->id,
                    'quantity_used' => 1,
                    'note' => 'ONT terpasang, SN '.$asset->serial_number,
                ]);
            }

            foreach ($materials as $sku => $quantity) {
                SpkService::addItem($workOrder, ['product_id' => $c->get('product', $sku), 'quantity_used' => $quantity]);
            }

            $workOrder->addMediaFromString(base64_decode(self::EVIDENCE_PNG))
                ->usingFileName('bukti-pekerjaan-'.strtolower($workOrder->code).'.png')
                ->withCustomProperties(['company_id' => $c->companyId(), 'type' => 'photo', 'uploaded_by' => $tech->id])
                ->toMediaCollection('evidence');

            SpkService::submitForReview(self::find($c, $key));
        });
    }

    public static function approve(DemoContext $c, string $key, string $reviewer): void
    {
        $c->as($reviewer, fn () => SpkService::approve(self::find($c, $key)));
    }

    public static function reject(DemoContext $c, string $key, string $reviewer, string $reason): void
    {
        $c->as($reviewer, fn () => SpkService::reject(self::find($c, $key), $reason));
    }

    public static function cancel(DemoContext $c, string $key, string $reason, string $by = 'manager'): void
    {
        $c->as($by, fn () => SpkService::cancel(self::find($c, $key), $reason));
    }

    /** The installation materials a job of that size takes. @return array<string, int> */
    public static function materials(DemoContext $c, bool $business): array
    {
        return $business
            ? ['KBL-DROP-1C' => $c->int(120, 300), 'CON-FAST-SC' => 4, 'PSV-CLAMP-DC' => $c->int(10, 24), 'PSV-SLEEVE-60' => 4, 'PSV-TIE-20' => 1]
            : ['KBL-DROP-1C' => $c->int(60, 160), 'CON-FAST-SC' => 2, 'PSV-CLAMP-DC' => $c->int(4, 12), 'PSV-SLEEVE-60' => 2];
    }
}
