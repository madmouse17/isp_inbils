<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Inventory\Models\StockMovement;
use Modules\Reporting\Http\Resources\StockMovementResource;
use Modules\Reporting\Queries\AssetUtilizationQuery;
use Modules\Reporting\Queries\BusinessMetricsQuery;
use Modules\Reporting\Queries\SalesKpiQuery;
use Modules\Reporting\Queries\SlaComplianceQuery;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;
use Modules\Reporting\Registry\DateBasis;
use Modules\Reporting\Registry\MetricDefinition;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\MetricUnit;
use Modules\Reporting\Registry\ReportType;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class MetricRegistryTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const REPORTS_WITH_METRICS = [
        ReportType::Business,
        ReportType::Sales,
        ReportType::Sla,
        ReportType::Asset,
        ReportType::Technician,
        ReportType::StockCard,
    ];

    // Contract between query payloads and the registry: a payload key without a definition,
    // or a definition without a payload key, fails here.

    public function test_business_payload_keys_equal_the_registry_keys(): void
    {
        $this->actingAs($this->createCompanyUser());

        $this->assertEqualsCanonicalizing(
            $this->registryKeys(ReportType::Business),
            array_keys(BusinessMetricsQuery::execute()),
        );
    }

    public function test_sla_payload_keys_equal_the_registry_keys(): void
    {
        $this->actingAs($this->createCompanyUser());

        $this->assertEqualsCanonicalizing(
            $this->registryKeys(ReportType::Sla),
            array_keys(SlaComplianceQuery::execute()),
        );
    }

    public function test_asset_payload_keys_equal_the_registry_keys(): void
    {
        $this->actingAs($this->createCompanyUser());

        $this->assertEqualsCanonicalizing(
            $this->registryKeys(ReportType::Asset),
            array_keys(AssetUtilizationQuery::execute()),
        );
    }

    public function test_technician_payload_keys_without_the_target_id_equal_the_registry_keys(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $technician = User::factory()->create([
            'company_id' => $admin->company_id,
            'is_active' => true,
        ]);
        $technician->assignRole('technician');
        EmployeeProfile::factory()->create([
            'company_id' => $admin->company_id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);

        $payloadKeys = array_diff(
            array_keys(TechnicianPerformanceQuery::execute($technician->id)),
            ['technician_id'],
        );

        $this->assertEqualsCanonicalizing($this->registryKeys(ReportType::Technician), $payloadKeys);
    }

    public function test_sales_row_keys_equal_the_registry_keys(): void
    {
        $admin = $this->createCompanyUser();
        EmployeeProfile::factory()->create([
            'company_id' => $admin->company_id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $result = SalesKpiQuery::execute((int) $admin->company_id);
        $registryKeys = $this->registryKeys(ReportType::Sales);

        $this->assertCount(1, $result['rows']);
        $this->assertEqualsCanonicalizing(
            $registryKeys,
            array_diff(array_keys($result['rows'][0]), ['id', 'name', 'is_active']),
        );
        $this->assertEqualsCanonicalizing($registryKeys, array_keys($result['totals']));
        $this->assertEqualsCanonicalizing($registryKeys, array_keys($result['unassigned']));
    }

    public function test_stock_card_registry_keys_exist_in_the_page_payload(): void
    {
        $this->assertEqualsCanonicalizing(
            ['movements', 'quantity', 'balance_after'],
            $this->registryKeys(ReportType::StockCard),
        );

        $movement = (new StockMovement())->forceFill([
            'movement_type' => 'receive',
            'quantity' => 4,
            'balance_after' => 9,
        ]);
        $row = (new StockMovementResource($movement))->toArray(Request::create('/'));

        $this->assertArrayHasKey('quantity', $row);
        $this->assertArrayHasKey('balance_after', $row);
    }

    // Every definition

    public function test_every_metric_is_version_v1(): void
    {
        // v1 = the formula as implemented on 2026-10-10. A deliberate version bump must update this
        // test and add characterization tests for the new formula.
        $this->assertNotEmpty(MetricRegistry::all());

        foreach (MetricRegistry::all() as $metric) {
            $this->assertSame('v1', $metric->version, "{$metric->report->value}.{$metric->key}");
            $this->assertSame('v1', $metric->toArray()['version']);
        }
    }

    public function test_report_formula_versions(): void
    {
        $this->assertSame('business.v1', MetricRegistry::formulaVersion(ReportType::Business));
        $this->assertSame('technician.v1', MetricRegistry::formulaVersion(ReportType::Technician));
        $this->assertSame('asset.v1', MetricRegistry::formulaVersion(ReportType::Asset));
        $this->assertSame('sla.v1', MetricRegistry::formulaVersion(ReportType::Sla));
        $this->assertSame('sales.v1', MetricRegistry::formulaVersion(ReportType::Sales));
        $this->assertSame('stock_card.v1', MetricRegistry::formulaVersion(ReportType::StockCard));
        $this->assertNull(MetricRegistry::formulaVersion(ReportType::AuditLog));
        $this->assertNull(MetricRegistry::formulaVersion(ReportType::Dictionary));
    }

    public function test_metric_counts_per_report(): void
    {
        $this->assertCount(13, MetricRegistry::forReport(ReportType::Business));
        $this->assertCount(7, MetricRegistry::forReport(ReportType::Sales));
        $this->assertCount(7, MetricRegistry::forReport(ReportType::Sla));
        $this->assertCount(5, MetricRegistry::forReport(ReportType::Asset));
        $this->assertCount(11, MetricRegistry::forReport(ReportType::Technician));
        $this->assertCount(3, MetricRegistry::forReport(ReportType::StockCard));
        $this->assertCount(0, MetricRegistry::forReport(ReportType::AuditLog));
        $this->assertCount(0, MetricRegistry::forReport(ReportType::Dictionary));
        $this->assertCount(46, MetricRegistry::all());
    }

    public function test_keys_are_unique_within_a_report(): void
    {
        foreach (self::REPORTS_WITH_METRICS as $report) {
            $keys = $this->registryKeys($report);

            $this->assertSame($keys, array_values(array_unique($keys)), "Duplicate key in {$report->value}");
        }
    }

    public function test_definitions_are_complete(): void
    {
        foreach (MetricRegistry::all() as $metric) {
            $name = "{$metric->report->value}.{$metric->key}";

            $this->assertNotSame('', trim($metric->key), $name);
            $this->assertNotSame('', trim($metric->label), "{$name} label");
            $this->assertNotSame('', trim($metric->definition), "{$name} definition");
            $this->assertNotSame('', trim($metric->formula), "{$name} formula");
            $this->assertNotSame('', trim($metric->scope), "{$name} scope");
            $this->assertNotEmpty($metric->sources, "{$name} sources");
            foreach ($metric->sources as $source) {
                $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $source, "{$name} source");
            }
        }
    }

    public function test_date_basis_matches_date_column(): void
    {
        foreach (MetricRegistry::all() as $metric) {
            $name = "{$metric->report->value}.{$metric->key}";

            if ($metric->dateBasis === DateBasis::Period) {
                $this->assertNotNull($metric->dateColumn, "{$name} is period based and needs a date column");
            } else {
                $this->assertSame(DateBasis::Snapshot, $metric->dateBasis);
                $this->assertNull($metric->dateColumn, "{$name} is a snapshot and must not name a date column");
            }
        }
    }

    public function test_no_em_dash_in_any_text_field(): void
    {
        $emDash = "\u{2014}";

        foreach (MetricRegistry::all() as $metric) {
            $texts = [
                $metric->key, $metric->label, $metric->definition, $metric->formula, $metric->scope,
                (string) $metric->dateColumn,
                ...$metric->sources, ...$metric->statuses, ...$metric->caveats,
            ];

            foreach ($texts as $text) {
                $this->assertStringNotContainsString($emDash, $text, "{$metric->report->value}.{$metric->key}");
            }
        }

        foreach (ReportType::cases() as $report) {
            $this->assertStringNotContainsString($emDash, $report->title());
            $this->assertStringNotContainsString($emDash, $report->description());
        }
    }

    public function test_units_are_known_values(): void
    {
        $known = ['currency', 'count', 'percent', 'minutes', 'rating', 'quantity', 'breakdown', 'records'];

        foreach (MetricRegistry::all() as $metric) {
            $this->assertContains($metric->unit->value, $known);
        }
    }

    public function test_definition_rejects_an_invalid_version_string(): void
    {
        foreach (['1', 'v0', 'v', 'v1.2', 'V1', ''] as $version) {
            try {
                $this->definitionWithVersion($version);
                $this->fail("Version [{$version}] must be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid version', $e->getMessage());
            }
        }

        $this->assertSame('v2', $this->definitionWithVersion('v2')->version);
    }

    public function test_meta_metrics_are_keyed_by_payload_key_and_belong_to_their_own_report(): void
    {
        $business = MetricRegistry::meta(ReportType::Business)['metrics'];

        $this->assertSame('MRR', $business['mrr']['label']);
        $this->assertSame(MetricUnit::Currency->value, $business['mrr']['unit']);
        $this->assertArrayNotHasKey('does_not_exist', $business);
        $this->assertArrayNotHasKey('mrr', MetricRegistry::meta(ReportType::Sla)['metrics']);
    }

    public function test_meta_shape(): void
    {
        foreach (self::REPORTS_WITH_METRICS as $report) {
            $meta = MetricRegistry::meta($report);

            $this->assertSame(['report', 'formula_version', 'metrics'], array_keys($meta));
            $this->assertSame($report->value, $meta['report']);
            $this->assertSame($report->value.'.v1', $meta['formula_version']);
            $this->assertSame($this->registryKeys($report), array_keys($meta['metrics']));

            foreach (MetricRegistry::forReport($report) as $metric) {
                $this->assertSame($metric->toArray(), $meta['metrics'][$metric->key]);
            }
        }
    }

    public function test_meta_of_a_report_without_metrics_is_empty(): void
    {
        $meta = MetricRegistry::meta(ReportType::AuditLog);

        $this->assertSame('audit_log', $meta['report']);
        $this->assertNull($meta['formula_version']);
        $this->assertSame([], $meta['metrics']);
    }

    /** @return list<string> */
    private function registryKeys(ReportType $report): array
    {
        return array_map(
            fn (MetricDefinition $metric): string => $metric->key,
            MetricRegistry::forReport($report),
        );
    }

    private function definitionWithVersion(string $version): MetricDefinition
    {
        return new MetricDefinition(
            report: ReportType::Business,
            key: 'probe',
            label: 'Probe',
            definition: 'Probe definition.',
            formula: 'Probe formula.',
            unit: MetricUnit::Count,
            dateBasis: DateBasis::Snapshot,
            sources: ['probe.column'],
            version: $version,
        );
    }
}
