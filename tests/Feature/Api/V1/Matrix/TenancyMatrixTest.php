<?php

namespace Tests\Feature\Api\V1\Matrix;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\PaymentAttempt;
use Tests\TestCase;

/**
 * Every api/v1 route that takes an id, walked from route:list, with the id of a row that exists in company A:
 * a peer (same company, same app) and a stranger (another company) get 404, never the data and never a side
 * effect. The owner reaching the same URL is the control that proves the id is real.
 */
class TenancyMatrixTest extends TestCase
{
    use BuildsMatrixWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
    }

    /** @return list<LaravelRoute> */
    private function idRoutes(): array
    {
        $routes = array_values(array_filter($this->apiRoutes(), fn (LaravelRoute $route): bool => str_contains($route->uri(), '{')));
        $this->assertCount(33, $routes, 'a route with an id was added or removed: add its owner row to BuildsMatrixWorld::realIds');

        foreach ($routes as $route) {
            $this->assertNotSame([], $this->realIds($this->shortName($route)), $route->uri().' has no owner fixture');
        }

        return $routes;
    }

    /** @return array<string, mixed> a payload that passes validation, so a 404 can only come from the tenancy check */
    private function payloadFor(LaravelRoute $route): array
    {
        return match (true) {
            str_ends_with($route->uri(), '/comments') => ['body' => 'Hello'],
            str_ends_with($route->uri(), '/resolve') => ['resolution_note' => 'Fixed on site'],
            str_ends_with($route->uri(), '/declare') => ['lines' => [['id' => $this->ids['material_return_line'], 'actual_used_qty' => 1, 'declared_good_return_qty' => 0, 'declared_discrepancy_qty' => 0]]],
            default => [],
        };
    }

    /** @return array<string, int|string> everything an attacker could change with one of these calls */
    private function snapshot(): array
    {
        return [
            'comments' => DB::table('ticket_comments')->count(),
            'tickets' => DB::table('tickets')->orderBy('id')->pluck('status', 'id')->toJson(),
            'work_orders' => DB::table('work_orders')->orderBy('id')->pluck('status', 'id')->toJson(),
            'media' => DB::table('media')->count(),
            'tokens' => DB::table('personal_access_tokens')->count(),
            'notifications_unread' => DB::table('notifications')->whereNull('read_at')->count(),
            'material_returns' => DB::table('material_return_cases')->orderBy('id')->pluck('status', 'id')->toJson(),
            'work_order_items' => DB::table('work_order_items')->orderBy('id')->pluck('quantity_used', 'id')->toJson(),
            'inventory_requests' => DB::table('inventory_requests')->orderBy('id')->pluck('status', 'id')->toJson(),
            'payment_attempts' => PaymentAttempt::withoutCompany()->count(),
        ];
    }

    public function test_the_owner_reads_every_id_route_the_attackers_below_are_refused(): void
    {
        foreach ($this->idRoutes() as $route) {
            if ($this->method($route) !== 'GET') {
                continue;
            }

            $owner = $this->fenceOf($route) === 'technician' ? $this->technicianToken : $this->customerToken;
            $this->api('GET', $this->urlOf($route, real: true), token: $owner)->assertOk();
        }
    }

    public function test_a_peer_and_a_stranger_get_404_on_every_id_route_and_change_nothing(): void
    {
        $tokens = [
            'peer customer' => ['customer', $this->issueToken($this->peerCustomerUser, 'customer')],
            'stranger customer' => ['customer', $this->issueToken($this->foreignCustomerUser, 'customer')],
            'peer technician' => ['technician', $this->issueToken($this->peerTechnician, 'technician')],
            'stranger technician' => ['technician', $this->issueToken($this->foreignTechnician, 'technician')],
        ];
        $before = $this->snapshot();

        foreach ($this->idRoutes() as $route) {
            $fence = $this->fenceOf($route);

            foreach ($tokens as $who => [$app, $token]) {
                if ($fence !== null && $fence !== $app) {
                    continue; // the other app's token is a 403 at the ability fence, walked in AccessMatrixTest
                }

                $response = $this->api($this->method($route), $this->urlOf($route, real: true), $this->payloadFor($route), $token);
                $label = "{$who} on {$this->method($route)} {$route->uri()}";

                // Another technician's work order is a record the caller cannot see: the same 404 as an unknown one.
                $this->assertSame(404, $response->status(), "{$label}: ".$response->getContent());
            }
        }

        $this->assertSame($before, $this->snapshot(), 'an attacker changed data');
    }

    public function test_lists_of_a_peer_and_a_stranger_hold_nothing_of_company_a(): void
    {
        $lists = [
            'customer' => ['customer/invoices', 'customer/payments', 'customer/subscriptions', 'customer/tickets', 'customer/work-orders', 'notifications'],
            'technician' => ['technician/my-work', 'technician/inventory-requests', 'technician/material-returns', 'notifications'],
        ];
        $actors = [
            ['customer', $this->peerCustomerUser], ['customer', $this->foreignCustomerUser],
            ['technician', $this->peerTechnician], ['technician', $this->foreignTechnician],
        ];

        foreach ($actors as [$app, $user]) {
            $token = $this->issueToken($user, $app);

            foreach ($lists[$app] as $uri) {
                $body = $this->api('GET', $uri, token: $token)->assertOk()->json();
                $rows = $body['data'] ?? $body;

                $this->assertSame([], array_values((array) $rows), "{$app} {$user->email} sees rows on {$uri}: ".json_encode($body));
            }
        }
    }

    public function test_the_device_list_shows_only_the_callers_own_tokens(): void
    {
        $stranger = $this->issueToken($this->foreignCustomerUser, 'customer', 'Stranger phone');

        $names = collect($this->api('GET', 'devices', token: $stranger)->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Stranger phone'], $names);
    }
}
