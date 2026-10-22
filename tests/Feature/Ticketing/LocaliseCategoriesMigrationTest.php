<?php

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;

/** M-2: the five seeded categories of a company that still carry the English name and no hint get the Indonesian ones. */
class LocaliseCategoriesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'Modules/Ticketing/database/migrations/2026_11_02_100200_localise_default_ticket_categories.php';

    private const NO_INTERNET_HINT = 'Periksa lampu modem. Cabut adaptor 10 detik lalu pasang lagi. Bila lampu LOS merah, kirim laporan ini.';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    private function category(Company $company, string $code, string $name, ?string $hint = null): int
    {
        return TicketCategory::query()->create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => $code,
            'default_sla_hours' => 4,
            'default_priority' => 'high',
            'customer_hint' => $hint,
            'is_active' => true,
        ])->id;
    }

    /** @return array{name: string, customer_hint: ?string} */
    private function row(int $id): array
    {
        $row = DB::table('ticket_categories')->where('id', $id)->first(['name', 'customer_hint']);

        return ['name' => $row->name, 'customer_hint' => $row->customer_hint];
    }

    public function test_up_renames_unchanged_english_rows_and_fills_a_missing_hint_in_every_company(): void
    {
        $first = $this->category(Company::factory()->create(), 'no_internet', 'No Internet');
        $second = $this->category(Company::factory()->create(), 'no_internet', 'No Internet');
        $fiber = $this->category(Company::factory()->create(), 'fiber_issue', 'Fiber Issue');

        $this->migration()->up();

        $this->assertSame(['name' => 'Internet mati', 'customer_hint' => self::NO_INTERNET_HINT], $this->row($first));
        $this->assertSame(['name' => 'Internet mati', 'customer_hint' => self::NO_INTERNET_HINT], $this->row($second));
        $this->assertSame('Kabel fiber bermasalah', $this->row($fiber)['name']);
        $this->assertSame('Jangan menarik atau menekuk kabel. Kirim foto kabel yang rusak bila bisa.', $this->row($fiber)['customer_hint']);
    }

    public function test_up_leaves_a_name_or_a_hint_the_company_wrote_itself(): void
    {
        $renamed = $this->category(Company::factory()->create(), 'no_internet', 'Gangguan total');
        $ownHint = $this->category(Company::factory()->create(), 'slow_connection', 'Slow Connection', 'Hubungi kami di 0812.');
        $unknownCode = $this->category(Company::factory()->create(), 'custom_code', 'No Internet');

        $this->migration()->up();

        $this->assertSame('Gangguan total', $this->row($renamed)['name']);
        $this->assertSame(self::NO_INTERNET_HINT, $this->row($renamed)['customer_hint'], 'a renamed row without a hint still gets the tip of its code');
        $this->assertSame(['name' => 'Koneksi lambat', 'customer_hint' => 'Hubungi kami di 0812.'], $this->row($ownHint));
        $this->assertSame(['name' => 'No Internet', 'customer_hint' => null], $this->row($unknownCode));
    }

    public function test_down_puts_back_only_what_up_wrote(): void
    {
        $untouched = $this->category(Company::factory()->create(), 'no_internet', 'No Internet');
        $edited = $this->category(Company::factory()->create(), 'no_internet', 'No Internet');
        $this->migration()->up();
        DB::table('ticket_categories')->where('id', $edited)->update(['name' => 'Gangguan total', 'customer_hint' => 'Tulis jam kejadian.']);

        $this->migration()->down();

        $this->assertSame(['name' => 'No Internet', 'customer_hint' => null], $this->row($untouched));
        $this->assertSame(['name' => 'Gangguan total', 'customer_hint' => 'Tulis jam kejadian.'], $this->row($edited));
    }

    public function test_up_is_safe_to_run_twice(): void
    {
        $id = $this->category(Company::factory()->create(), 'packet_loss', 'Packet Loss');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('Koneksi putus-sambung', $this->row($id)['name']);
    }
}
