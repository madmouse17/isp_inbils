<?php

namespace Tests\Feature\Reporting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/**
 * F10-U02: a user of a subsidiary who asks for the consolidated report is told where it lives and, when they may go
 * there, offered the switch to the Head on the Reports page (the existing company switch). The Head is handed over by the
 * refusal itself, once.
 */
class ConsolidatedSwitchHintTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->buildGroup();
    }

    public function test_the_refusal_hands_the_reports_page_the_head_once_and_the_switch_goes_there(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $parent = ['id' => $this->head->public_id, 'name' => 'Group Head'];
        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id]);

        $this->get(route('admin.reports.consolidated'))
            ->assertRedirect(route('admin.reports.index'))
            ->assertSessionHas('consolidated_parent', $parent);

        $this->get(route('admin.reports.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('consolidatedParent', $parent)
            ->where('companyHierarchy.parent', [...$parent, 'can_switch' => true]));

        // Shown once: the next visit of the page has nothing to say about it.
        $this->get(route('admin.reports.index'))->assertOk()->assertInertia(fn ($page) => $page->where('consolidatedParent', null));

        // The button posts what the sidebar posts, and the report opens in the Head.
        $this->post(route('admin.company.switch'), ['company_id' => $parent['id']])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.reports.consolidated'))->assertOk();
    }

    public function test_the_file_link_of_a_stale_tab_hands_over_the_head_too(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id]);

        $this->get(route('admin.reports.consolidated.export'))
            ->assertRedirect(route('admin.reports.index'))
            ->assertSessionHas('consolidated_parent', ['id' => $this->head->public_id, 'name' => 'Group Head']);
    }

    public function test_a_user_who_may_not_go_to_the_head_is_told_where_it_is_but_given_no_switch(): void
    {
        $user = $this->groupUser($this->subA);

        $this->actingAs($user)->get(route('admin.reports.consolidated'))->assertRedirect(route('admin.reports.index'));

        $this->get(route('admin.reports.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('consolidatedParent.id', $this->head->public_id)
            ->where('companyHierarchy.parent.can_switch', false));
    }

    public function test_nothing_is_handed_over_without_the_permission_or_in_the_head(): void
    {
        // Without report.consolidated.view there is nothing to explain: a plain refusal.
        $manager = $this->groupUser($this->subA, 'manager');
        $this->actingAs($manager)->get(route('admin.reports.consolidated'))->assertForbidden()->assertSessionMissing('consolidated_parent');

        // In the Head the report opens, and its Reports page has no hint.
        $headAdmin = $this->groupUser($this->head);
        $this->actingAs($headAdmin)->get(route('admin.reports.consolidated'))->assertOk();
        $this->get(route('admin.reports.index'))->assertOk()->assertInertia(fn ($page) => $page->where('consolidatedParent', null));
    }
}
