<?php

namespace Tests\Feature\Reporting;

use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\ReportType;
use Tests\TestCase;

/**
 * The hub, the page heading, the export title and the dictionary all read ReportType::title(): one
 * canonical Indonesian name per report, and no em dash in any text a person reads.
 */
class ReportTypeCatalogTest extends TestCase
{
    private const EM_DASH = "\u{2014}";

    public function test_every_report_has_its_canonical_title(): void
    {
        $this->assertSame([
            'business' => 'Metrik Bisnis',
            'sales' => 'KPI Penjualan',
            'technician' => 'Kinerja Teknisi',
            'asset' => 'Pemanfaatan Aset',
            'sla' => 'Kepatuhan SLA',
            'stock_card' => 'Kartu Stok',
            'audit_log' => 'Log Audit',
            'dictionary' => 'Kamus Data',
        ], array_combine(
            array_map(fn (ReportType $report): string => $report->value, ReportType::cases()),
            array_map(fn (ReportType $report): string => $report->title(), ReportType::cases()),
        ));
    }

    public function test_every_description_is_filled_and_free_of_dashes(): void
    {
        foreach (ReportType::cases() as $report) {
            $this->assertNotSame('', trim($report->description()), $report->value);
            $this->assertStringNotContainsString(self::EM_DASH, $report->description(), $report->value);
            $this->assertStringNotContainsString(' - ', $report->description(), $report->value);
        }
    }

    public function test_the_hub_card_holds_key_title_description_and_a_relative_link(): void
    {
        foreach (ReportType::cases() as $report) {
            $card = $report->toCard();

            $this->assertSame(['key', 'title', 'description', 'href'], array_keys($card));
            $this->assertSame($report->value, $card['key']);
            $this->assertSame($report->title(), $card['title']);
            $this->assertSame($report->description(), $card['description']);
            $this->assertSame(route($report->routeName(), [], false), $card['href']);
        }
    }

    public function test_no_registry_text_holds_an_em_dash(): void
    {
        foreach (MetricRegistry::all() as $metric) {
            $name = "{$metric->report->value}.{$metric->key}";

            foreach ([$metric->label, $metric->definition, $metric->formula, $metric->scope, ...$metric->caveats] as $text) {
                $this->assertNotSame('', trim($text), $name);
                $this->assertStringNotContainsString(self::EM_DASH, $text, $name);
            }
        }
    }
}
