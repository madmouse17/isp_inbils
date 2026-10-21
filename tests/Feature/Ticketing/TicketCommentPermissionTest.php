<?php

namespace Tests\Feature\Ticketing;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TicketCommentPermissionTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_add_comment_requires_permission(): void
    {
        $admin = $this->createCompanyUser();
        $ticket = $this->makeOpenTicket($admin);

        $commenter = User::factory()->create([
            'company_id' => $admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->grantPermissions($commenter, ['app.web', 'ticket.view']);

        $this->actingAs($commenter)
            ->post(route('admin.tickets.comments.store', $ticket), [
                'body' => 'Need update',
                'is_internal' => false,
            ])
            ->assertForbidden();
    }

    private function makeOpenTicket(User $admin): Ticket
    {
        $category = TicketCategory::create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return TicketService::create([
            'title' => 'Comment permission',
            'description' => 'Permission gate',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'medium',
        ], $admin->id);
    }
}
