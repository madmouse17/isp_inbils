<?php

namespace Tests\Feature\Integrity;

use App\Http\Middleware\ResolveActiveCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Unit;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/**
 * Item 18: a form opened in tab 1 on company A must not save into company B after tab 2 switched the
 * session to B. The client sends X-Inbils-Company with the company the page was rendered in.
 */
class ActiveCompanyTokenTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
    }

    /** A user homed in head and a member of subA, with the session switched to subA (the "other tab"). */
    private function userOnSubA(): User
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id])->assertRedirect();

        return $user;
    }

    public function test_a_create_from_a_tab_still_on_company_a_is_refused_after_switching_to_b(): void
    {
        $this->userOnSubA();

        $this->from(route('admin.units.index'))
            ->withHeaders(['X-Inertia' => 'true', ResolveActiveCompany::HEADER => $this->head->public_id])
            ->post(route('admin.units.store'), ['name' => 'Stale Unit', 'symbol' => 'stl'])
            ->assertStatus(302)
            ->assertRedirect(route('admin.units.index'))
            ->assertSessionHasErrors(['conflict' => ResolveActiveCompany::MOVED_MESSAGE]);

        $this->assertSame(0, Unit::withoutCompany()->where('name', 'Stale Unit')->count());
    }

    public function test_a_json_post_from_a_stale_tab_is_refused_with_409(): void
    {
        $this->userOnSubA();

        // What the axios dialogs (quick create, previews) send once app.tsx puts the header on them.
        $this->withHeaders([ResolveActiveCompany::HEADER => $this->head->public_id])
            ->postJson(route('admin.units.store'), ['name' => 'Stale Json Unit', 'symbol' => 'sju'])
            ->assertStatus(409);

        $this->assertSame(0, Unit::withoutCompany()->where('name', 'Stale Json Unit')->count());
    }

    public function test_an_update_from_a_stale_tab_redirects_with_303(): void
    {
        $this->userOnSubA();
        $unit = Unit::factory()->create(['company_id' => $this->subA->id, 'name' => 'Keep', 'symbol' => 'kp']);

        $this->from(route('admin.units.index'))
            ->withHeaders(['X-Inertia' => 'true', ResolveActiveCompany::HEADER => $this->head->public_id])
            ->put(route('admin.units.update', $unit), ['name' => 'Changed', 'symbol' => 'chg'])
            ->assertStatus(303)
            ->assertSessionHasErrors('conflict');

        $this->assertSame('Keep', $unit->fresh()->name);
    }

    public function test_the_active_company_or_no_header_passes(): void
    {
        $this->userOnSubA();

        $this->withHeaders([ResolveActiveCompany::HEADER => $this->subA->public_id])
            ->post(route('admin.units.store'), ['name' => 'With Header', 'symbol' => 'wh'])
            ->assertSessionHasNoErrors();
        $this->post(route('admin.units.store'), ['name' => 'No Header', 'symbol' => 'nh'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [$this->subA->id, $this->subA->id],
            Unit::withoutCompany()->whereIn('name', ['With Header', 'No Header'])->orderBy('id')->pluck('company_id')->all(),
        );
    }

    public function test_switching_company_is_never_refused(): void
    {
        $this->userOnSubA();

        $this->withHeaders([ResolveActiveCompany::HEADER => $this->subA->public_id])
            ->post(route('admin.company.switch'), ['company_id' => $this->head->public_id])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasNoErrors();
    }

    public function test_reads_ignore_the_header(): void
    {
        $this->userOnSubA();

        $this->withHeaders([ResolveActiveCompany::HEADER => $this->head->public_id])
            ->get(route('admin.units.index'))
            ->assertOk();
    }
}
