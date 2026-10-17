<?php

namespace Tests\Unit\SPK;

use Modules\SPK\Models\WorkOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The ISP's words for an SPK type: one list for the technician and the customer apps, so a type is never shown as its key.
 */
class WorkOrderTypeLabelTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function types(): array
    {
        return [
            'installation' => ['installation', 'Instalasi'],
            'maintenance' => ['maintenance', 'Perawatan'],
            'upgrade' => ['upgrade_service', 'Peningkatan layanan'],
            'relocation' => ['relocation', 'Pindah lokasi'],
            'a type nobody named keeps its key rather than showing nothing' => ['site_survey', 'site_survey'],
        ];
    }

    #[DataProvider('types')]
    public function test_a_type_has_its_indonesian_label(string $type, string $label): void
    {
        $this->assertSame($label, (new WorkOrder())->forceFill(['type' => $type])->typeLabel());
    }
}
