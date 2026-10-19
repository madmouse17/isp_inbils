<?php

namespace Tests\Feature\Api\V1\Matrix;

use App\Http\Middleware\Api\EnforceIdempotency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketComment;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Idempotency-Key on every mutating api/v1 route, walked from route:list: a missing key is 428, a replay is
 * byte-identical and never runs the action twice, the same key with another body is 422. The named tests then
 * prove the replay on routes whose second run would visibly differ.
 */
class IdempotencyMatrixTest extends TestCase
{
    use BuildsMatrixWorld;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildWorld();
    }

    /** @return list<Route> */
    private function mutatingRoutes(): array
    {
        $routes = array_values(array_filter($this->apiRoutes(), fn ($route): bool => $this->needsIdempotencyKey($route)));
        $this->assertCount(29, $routes, 'a mutating route was added or removed: the matrix must see it');

        return $routes;
    }

    private function tokenFor(?string $fence): string
    {
        return $fence === 'technician' ? $this->technicianToken : $this->customerToken;
    }

    public function test_every_mutating_route_refuses_a_call_without_a_key_with_428(): void
    {
        foreach ($this->mutatingRoutes() as $route) {
            $this->api($this->method($route), $this->urlOf($route, real: true), token: $this->tokenFor($this->fenceOf($route)), headers: ['Idempotency-Key' => null])
                ->assertStatus(428)
                ->assertJsonPath('code', 'idempotency_key_required');
        }
    }

    public function test_every_mutating_route_refuses_a_key_that_is_not_a_uuid_with_422(): void
    {
        foreach ($this->mutatingRoutes() as $route) {
            $this->api($this->method($route), $this->urlOf($route, real: true), token: $this->tokenFor($this->fenceOf($route)), headers: ['Idempotency-Key' => 'retry-1'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['Idempotency-Key']);
        }
    }

    public function test_the_session_endpoints_take_no_key(): void
    {
        $this->api('POST', 'auth/token/refresh', token: $this->customerToken, headers: ['Idempotency-Key' => null])->assertOk();
        $this->api('POST', 'auth/logout', token: $this->technicianToken, headers: ['Idempotency-Key' => null])->assertNoContent();
    }

    public function test_on_every_mutating_route_a_replay_is_identical_and_another_body_with_the_same_key_is_422(): void
    {
        foreach ($this->mutatingRoutes() as $route) {
            $method = $this->method($route);
            $uri = $this->urlOf($route, real: false);
            $token = $this->tokenFor($this->fenceOf($route));
            $key = (string) Str::uuid();

            $first = $this->api($method, $uri, ['probe' => 'a'], $token, ['Idempotency-Key' => $key]);
            $this->assertLessThan(500, $first->status(), $route->uri().' answered a server error to a plain call');

            $replay = $this->api($method, $uri, ['probe' => 'a'], $token, ['Idempotency-Key' => $key]);
            $this->assertSame($first->status(), $replay->status(), $route->uri());
            $this->assertSame($first->getContent(), $replay->getContent(), $route->uri());
            $replay->assertHeader('Idempotent-Replayed', 'true');

            $this->api($method, $uri, ['probe' => 'b'], $token, ['Idempotency-Key' => $key])
                ->assertStatus(422)
                ->assertJsonPath('code', 'idempotency_conflict');
        }
    }

    public function test_a_replayed_ticket_comment_and_a_replayed_ticket_exist_once(): void
    {
        $tickets = Ticket::query()->count();
        $key = (string) Str::uuid();
        $payload = ['category_id' => $this->ids['category'], 'title' => 'Retry me'];

        $first = $this->api('POST', 'customer/tickets', $payload, $this->customerToken, ['Idempotency-Key' => $key])->assertCreated();
        $replay = $this->api('POST', 'customer/tickets', $payload, $this->customerToken, ['Idempotency-Key' => $key])->assertCreated();

        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame($tickets + 1, Ticket::query()->count());

        $comments = TicketComment::query()->count();
        $commentKey = (string) Str::uuid();
        $url = 'customer/tickets/'.$this->ids['customer_ticket'].'/comments';

        $one = $this->api('POST', $url, ['body' => 'Still down'], $this->customerToken, ['Idempotency-Key' => $commentKey])->assertCreated();
        $two = $this->api('POST', $url, ['body' => 'Still down'], $this->customerToken, ['Idempotency-Key' => $commentKey])->assertCreated();

        $this->assertSame($one->getContent(), $two->getContent());
        $this->assertSame($comments + 1, TicketComment::query()->count());

        $technicianComments = TicketComment::query()->count();
        $techKey = (string) Str::uuid();
        $techUrl = 'technician/tickets/'.$this->ids['technician_ticket'].'/comments';
        $this->api('POST', $techUrl, ['body' => 'On my way'], $this->technicianToken, ['Idempotency-Key' => $techKey])->assertCreated();
        $this->api('POST', $techUrl, ['body' => 'On my way'], $this->technicianToken, ['Idempotency-Key' => $techKey])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($technicianComments + 1, TicketComment::query()->count());
    }

    public function test_a_replayed_start_does_not_hit_the_state_guard_the_second_time(): void
    {
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->companyA->id, 'status' => 'assigned', 'assigned_to' => $this->technician->id, 'created_by' => $this->technician->id,
        ]);
        $key = (string) Str::uuid();
        $url = "technician/work-orders/{$workOrder->public_id}/start";

        $first = $this->api('POST', $url, token: $this->technicianToken, headers: ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $replay = $this->api('POST', $url, token: $this->technicianToken, headers: ['Idempotency-Key' => $key])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->getContent(), $replay->getContent());

        // A new key is a new action, and the guard refuses a second start.
        $this->api('POST', $url, token: $this->technicianToken)->assertForbidden();
    }

    public function test_a_replayed_delete_answers_204_again_instead_of_404(): void
    {
        $key = (string) Str::uuid();
        $this->issueToken($this->customerUser, 'customer', 'Second phone');
        $deviceId = $this->customerUser->tokens()->where('name', 'Second phone')->firstOrFail()->public_id;

        $this->api('DELETE', "devices/{$deviceId}", token: $this->customerToken, headers: ['Idempotency-Key' => $key])->assertNoContent();
        $this->api('DELETE', "devices/{$deviceId}", token: $this->customerToken, headers: ['Idempotency-Key' => $key])->assertNoContent()->assertHeader('Idempotent-Replayed', 'true');
        $this->api('DELETE', "devices/{$deviceId}", token: $this->customerToken)->assertNotFound();
    }

    public function test_a_replayed_read_all_and_a_replayed_pay_return_the_stored_answer(): void
    {
        foreach ([['POST', 'notifications/read-all', 204], ['POST', 'customer/invoices/'.$this->ids['invoice'].'/pay', null]] as [$method, $url, $status]) {
            $key = (string) Str::uuid();
            $first = $this->api($method, $url, token: $this->customerToken, headers: ['Idempotency-Key' => $key]);
            $replay = $this->api($method, $url, token: $this->customerToken, headers: ['Idempotency-Key' => $key]);

            $this->assertLessThan(500, $first->status(), $url);
            $this->assertSame($status ?? $first->status(), $replay->status(), $url);
            $this->assertSame($first->getContent(), $replay->getContent(), $url);
            $replay->assertHeader('Idempotent-Replayed', 'true');
        }
    }

    public function test_a_replayed_evidence_upload_stores_one_photo_even_though_the_multipart_boundary_changes(): void
    {
        $key = (string) Str::uuid();
        $url = '/api/v1/technician/work-orders/'.$this->ids['work_order'].'/evidence';
        $before = Media::query()->where('collection_name', 'evidence')->count();

        $send = function () use ($key, $url) {
            app('auth')->forgetGuards();
            app('auth')->shouldUse('web');

            return $this->post($url, ['file' => UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG)), 'caption' => 'ODP closed'], [
                'Authorization' => 'Bearer '.$this->technicianToken, 'Accept' => 'application/json', EnforceIdempotency::HEADER => $key,
            ]);
        };

        $first = $send()->assertCreated();
        $replay = $send()->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame($before + 1, Media::query()->where('collection_name', 'evidence')->count());
    }
}
