<?php

namespace Tests\Feature\Integrity;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventDuplicateWebSubmit;
use App\Models\Core\Customer;
use App\Models\Core\Vehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/**
 * Mechanism (a) of integrity-hardening: an identical write to an admin route by the same user in the same company
 * inside ten seconds is a double submit, and it is refused with the shared 409 shape.
 */
class PreventDuplicateWebSubmitTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    /** Real routes the middleware exempts today; a renamed route would silently lose its exemption. */
    private const EXEMPT_ROUTES = [
        'admin.company.switch',
        'admin.notifications.read',
        'admin.notifications.read-all',
        'admin.invoices.generate-preview',
        'admin.subscriptions.network-account.reveal',
    ];

    /** Exempt by agreement with the tenant-integrations lane; the route does not exist yet. */
    private const EXEMPT_PLANNED_ROUTES = ['admin.company.integrations.test'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.duplicate_submit.enabled' => true]);
        $this->buildGroup();
        $this->admin = $this->groupUser($this->head, 'admin', $this->subA);
    }

    public function test_a_second_identical_payment_within_ten_seconds_is_refused(): void
    {
        $invoice = $this->sentInvoice();
        $url = route('admin.invoices.payments.store', $invoice);
        $body = ['amount' => 50000, 'method' => 'cash'];

        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true');

        $this->post($url, $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertRefused($this->post($url, $body));

        $this->assertSame(1, Payment::withoutCompany()->where('invoice_id', $invoice->id)->count());
        $this->assertSame('partial', $invoice->fresh()->status);
    }

    public function test_a_different_body_passes_within_the_window(): void
    {
        $invoice = $this->sentInvoice();
        $url = route('admin.invoices.payments.store', $invoice);

        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true');

        $this->post($url, ['amount' => 30000, 'method' => 'cash'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, ['amount' => 20000, 'method' => 'cash'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertRefused($this->post($url, ['amount' => 20000, 'method' => 'cash']));

        $this->assertSame(2, Payment::withoutCompany()->where('invoice_id', $invoice->id)->count());
    }

    public function test_the_same_post_after_ten_seconds_goes_through(): void
    {
        $invoice = $this->sentInvoice();
        $url = route('admin.invoices.payments.store', $invoice);
        $body = ['amount' => 25000, 'method' => 'cash'];

        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true');

        $this->post($url, $body)->assertRedirect()->assertSessionHasNoErrors();

        $this->travel(5)->seconds();
        $this->assertRefused($this->post($url, $body));

        $this->travel(6)->seconds();
        $this->post($url, $body)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Payment::withoutCompany()->where('invoice_id', $invoice->id)->count());
    }

    public function test_a_refused_attempt_frees_its_key(): void
    {
        $invoice = $this->sentInvoice();
        $url = route('admin.invoices.payments.store', $invoice);
        $tooMuch = ['amount' => 150000, 'method' => 'cash'];
        $tooMuchMessage = 'Jumlah pembayaran melebihi sisa tagihan.';

        $this->actingAs($this->admin);

        // An abort(422): the identical retry is a 422 again, never the duplicate 409.
        $this->postJson($url, $tooMuch)->assertStatus(422)->assertJsonPath('message', $tooMuchMessage);
        $this->postJson($url, $tooMuch)->assertStatus(422)->assertJsonPath('message', $tooMuchMessage);

        // The world changed in the meantime: the very same request now reaches the controller and is booked.
        $invoice->update(['total' => '200000.00']);
        $this->postJson($url, $tooMuch)->assertRedirect();
        $this->assertSame(1, Payment::withoutCompany()->where('invoice_id', $invoice->id)->count());

        // A validation error (redirect back with errors) frees the key too.
        $invalid = ['amount' => 1000, 'method' => 'barter'];
        $this->withHeader('X-Inertia', 'true');
        $this->post($url, $invalid)->assertSessionHasErrors('method');
        $this->post($url, $invalid)->assertSessionHasErrors('method')->assertSessionDoesntHaveErrors('conflict');
    }

    public function test_a_json_client_gets_409_json(): void
    {
        $body = ['plate_number' => 'B 1001 JSN'];

        $this->actingAs($this->admin);

        $this->postJson(route('admin.vehicles.store'), $body)->assertRedirect();
        $this->postJson(route('admin.vehicles.store'), $body)
            ->assertStatus(409)
            ->assertExactJson(['message' => PreventDuplicateWebSubmit::MESSAGE, 'error' => 'conflict']);

        $this->assertSame(1, Vehicle::withoutCompany()->where('plate_number', 'B 1001 JSN')->count());
    }

    public function test_an_inertia_put_conflict_redirects_with_303(): void
    {
        $vehicle = Vehicle::factory()->create(['company_id' => $this->head->id]);
        $url = route('admin.vehicles.update', $vehicle);
        $body = ['plate_number' => 'B 2002 PUT'];

        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true');

        $this->put($url, $body)->assertStatus(303)->assertSessionHasNoErrors();
        $this->put($url, $body)
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => PreventDuplicateWebSubmit::MESSAGE]);
    }

    /** The rule lives in the renderer, because a conflict thrown before HandleInertiaRequests never reaches its 302-to-303 step. */
    public function test_the_conflict_renderer_answers_303_for_put_patch_and_delete_and_302_for_post(): void
    {
        Route::middleware('web')
            ->withoutMiddleware(HandleInertiaRequests::class)
            ->match(['POST', 'PUT', 'PATCH', 'DELETE'], '_probe/stale', fn () => throw new ConflictHttpException('Stale.'));

        $this->withHeader('X-Inertia', 'true');

        foreach (['put', 'patch', 'delete'] as $verb) {
            $this->{$verb}('/_probe/stale')->assertStatus(303)->assertSessionHasErrors(['conflict' => 'Stale.']);
        }

        $this->post('/_probe/stale')->assertStatus(302)->assertSessionHasErrors(['conflict' => 'Stale.']);
    }

    public function test_get_and_exempt_routes_are_never_refused(): void
    {
        foreach (self::EXEMPT_ROUTES as $name) {
            $this->assertTrue(Route::has($name), "{$name} is not a route any more: update PreventDuplicateWebSubmit::EXCEPT.");
        }

        $this->actingAs($this->admin);

        $this->get(route('admin.vehicles.index'))->assertOk();
        $this->get(route('admin.vehicles.index'))->assertOk();

        // The exemption is by route name, so a probe carrying the name proves the list without needing each controller's fixture.
        foreach ([...self::EXEMPT_ROUTES, ...self::EXEMPT_PLANNED_ROUTES, 'portal.probe', 'platform.probe'] as $name) {
            Route::middleware('web')->post("_probe/{$name}", fn () => response()->noContent())->name($name);

            $this->post("/_probe/{$name}", ['same' => 'body'])->assertNoContent();
            $this->post("/_probe/{$name}", ['same' => 'body'])->assertNoContent();
        }

        Route::middleware('web')->post('_probe/counted', fn () => response()->noContent())->name('admin.probe.counted');
        $this->post('/_probe/counted', ['same' => 'body'])->assertNoContent();
        $this->post('/_probe/counted', ['same' => 'body'])->assertStatus(409);
    }

    public function test_every_unsafe_verb_is_guarded(): void
    {
        $this->actingAs($this->admin);

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            Route::middleware('web')->{$verb}("_probe/verb-{$verb}", fn () => response()->noContent())->name("admin.probe.verb-{$verb}");

            $this->{$verb}("/_probe/verb-{$verb}", ['same' => 'body'])->assertNoContent();
            $this->{$verb}("/_probe/verb-{$verb}", ['same' => 'body'])->assertStatus(409);
        }
    }

    public function test_the_guard_is_off_in_testing_unless_a_test_switches_it_on(): void
    {
        $this->assertFalse((require config_path('security.php'))['duplicate_submit']['enabled'], 'duplicate_submit.enabled must default to off in the testing environment.');

        config(['security.duplicate_submit.enabled' => false]);
        Route::middleware('web')->post('_probe/off', fn () => response()->noContent())->name('admin.probe.off');
        $this->actingAs($this->admin);

        $this->post('/_probe/off', ['same' => 'body'])->assertNoContent();
        $this->post('/_probe/off', ['same' => 'body'])->assertNoContent();
    }

    public function test_a_guest_is_sent_to_login_not_refused(): void
    {
        $this->post(route('admin.vehicles.store'), ['plate_number' => 'B 3003 GST'])->assertRedirect(route('login'));
        $this->post(route('admin.vehicles.store'), ['plate_number' => 'B 3003 GST'])->assertRedirect(route('login'));
    }

    public function test_keys_are_per_user_and_per_company(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->head->id]);
        $draft = ['customer_id' => $customer->public_id, 'issue_date' => '2026-07-01', 'due_date' => '2026-07-31'];
        $colleague = $this->groupUser($this->head);

        // Two people in one company send the same draft: both are real, each one is booked.
        $this->actingAs($this->admin)->post(route('admin.invoices.store'), $draft)->assertRedirect(route('admin.invoices.index'));
        $this->actingAs($colleague)->post(route('admin.invoices.store'), $draft)->assertRedirect(route('admin.invoices.index'));
        $this->assertSame(2, Invoice::withoutCompany()->where('customer_id', $customer->id)->count());

        // One person sends the same body in two companies: the second company is not a double submit.
        $vehicle = ['plate_number' => 'B 4004 KEY'];
        $this->actingAs($this->admin)->post(route('admin.vehicles.store'), $vehicle)->assertRedirect();
        $this->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id])->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.vehicles.store'), $vehicle)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(
            [$this->head->id, $this->subA->id],
            Vehicle::withoutCompany()->where('plate_number', 'B 4004 KEY')->orderBy('company_id')->pluck('company_id')->all(),
        );

        // ...but the same body again in that company is.
        $this->withHeader('X-Inertia', 'true');
        $this->assertRefused($this->post(route('admin.vehicles.store'), $vehicle));
    }

    public function test_the_same_file_twice_is_refused_and_another_file_passes(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $customer = Customer::factory()->create(['company_id' => $this->head->id]);
        $upload = fn (string $bytes): TestResponse => $this->post(route('admin.documents.media.store'), [
            'model_type' => Customer::class,
            'model_id' => $customer->public_id,
            'file' => UploadedFile::fake()->createWithContent('scan.pdf', $bytes),
            'collection' => 'documents',
        ]);

        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true');

        $upload('first scan')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertRefused($upload('first scan'));
        $upload('another scan')->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, DB::table('media')->where('model_type', Customer::class)->where('model_id', $customer->id)->count());
    }

    public function test_a_double_click_on_create_makes_one_ticket(): void
    {
        $category = TicketCategory::create([
            'company_id' => $this->head->id,
            'name' => 'Support',
            'code' => 'SUP',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
        $body = [
            'title' => 'Double click',
            'description' => 'Sent twice',
            'source' => 'internal',
            'category_id' => $category->public_id,
            'priority' => 'medium',
        ];

        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true');

        $this->post(route('admin.tickets.store'), $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertRefused($this->post(route('admin.tickets.store'), $body));

        $this->assertSame(1, Ticket::withoutCompany()->where('title', 'Double click')->count());
    }

    private function sentInvoice(string $total = '100000.00'): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $this->head->id,
            'customer_id' => Customer::factory()->create(['company_id' => $this->head->id])->id,
            'status' => 'sent',
            'total' => $total,
        ]);
    }

    /** The shared Inertia 409: back with errors.conflict, and the flash the global toast shows. */
    private function assertRefused(TestResponse $response): void
    {
        $response->assertRedirect()
            ->assertSessionHasErrors(['conflict' => PreventDuplicateWebSubmit::MESSAGE])
            ->assertSessionHas('error', PreventDuplicateWebSubmit::MESSAGE);
    }
}
