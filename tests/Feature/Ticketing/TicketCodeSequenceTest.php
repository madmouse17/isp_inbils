<?php

namespace Tests\Feature\Ticketing;

use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TicketCodeSequenceTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_ticket_codes_come_from_the_engine_and_restart_each_year(): void
    {
        $admin = $this->createCompanyUser();
        $category = $this->category($admin->company_id);
        $codes = [];

        foreach (['2026-12-30 09:00:00', '2026-12-31 09:00:00', '2027-01-01 09:00:00'] as $moment) {
            $this->travelTo($moment);
            $codes[] = $this->ticket($admin, $category)->code;
        }

        $this->assertSame(['TKT-2026-00001', 'TKT-2026-00002', 'TKT-2027-00001'], $codes);
    }

    public function test_each_company_counts_its_own_tickets(): void
    {
        $first = $this->createCompanyUser();
        $firstCode = $this->ticket($first, $this->category($first->company_id))->code;

        $second = $this->createCompanyUser();
        $secondCode = $this->ticket($second, $this->category($second->company_id))->code;

        $this->assertSame('TKT-'.now('Asia/Jakarta')->year.'-00001', $firstCode);
        $this->assertSame($firstCode, $secondCode);
    }

    private function category(int $companyId): TicketCategory
    {
        return TicketCategory::create([
            'company_id' => $companyId,
            'name' => 'General Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
    }

    private function ticket(User $creator, TicketCategory $category): Ticket
    {
        CompanyService::useCompanyForTests((int) $creator->company_id);

        return TicketService::create([
            'title' => 'Internet trouble',
            'description' => 'Cannot connect',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'medium',
        ], $creator->id);
    }
}
