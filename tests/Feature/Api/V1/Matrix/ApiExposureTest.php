<?php

namespace Tests\Feature\Api\V1\Matrix;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * No api/v1 body carries an integer record id (spec .scratch/opaque-ids, D2): every `id`, `*_id`, `*_ids`, `*_by` and
 * `assigned_to` is a public id (a 26-character ULID), a notification's UUID or null, so the apps can never learn the
 * volume behind a record or guess a neighbour. Walked like SecretKeyWalkTest, over the owner's GET routes of both apps
 * and over the calls that answer with a record after a write, in the world of BuildsMatrixWorld.
 */
class ApiExposureTest extends TestCase
{
    use BuildsMatrixWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
    }

    public function test_no_get_response_of_either_app_sends_an_integer_id(): void
    {
        $walked = 0;

        foreach ($this->apiRoutes() as $route) {
            if ($this->method($route) !== 'GET' || in_array($this->shortName($route), ['media.show', 'customer.invoices.receipt'], true)) {
                continue; // media and the receipt stream a file
            }

            $technician = $this->fenceOf($route) === 'technician';
            $response = $this->api('GET', $this->urlOf($route, real: true), token: $technician ? $this->technicianToken : $this->customerToken)->assertOk();

            $this->assertOnlyPublicIds($response->json(), 'GET '.$route->uri());
            $walked++;
        }

        $this->assertSame(25, $walked, 'a GET route was added or removed');
    }

    public function test_the_answers_to_writes_send_no_integer_id_either(): void
    {
        $answers = [
            'POST auth/login' => $this->api('POST', 'auth/login', ['email' => $this->peerCustomerUser->email, 'password' => 'password', 'device_name' => 'Pixel 8', 'app' => 'customer'])->assertOk(),
            'POST customer/tickets' => $this->api('POST', 'customer/tickets', ['category_id' => $this->ids['category'], 'title' => 'Exposure check'], $this->customerToken)->assertCreated(),
            'POST customer/tickets/{id}/comments' => $this->api('POST', 'customer/tickets/'.$this->ids['customer_ticket'].'/comments', ['body' => 'Hello'], $this->customerToken)->assertCreated(),
            'POST me/device-token' => $this->api('POST', 'me/device-token', ['token' => 'fcm-token-exposure', 'app' => 'customer', 'platform' => 'android'], $this->customerToken),
            'POST technician/work-orders/{wo}/start' => $this->api('POST', 'technician/work-orders/'.$this->ids['customer_work_order'].'/start', token: $this->technicianToken)->assertOk(),
            'POST technician/tickets/{id}/comments' => $this->api('POST', 'technician/tickets/'.$this->ids['technician_ticket'].'/comments', ['body' => 'On my way'], $this->technicianToken)->assertCreated(),
        ];

        foreach ($answers as $call => $response) {
            $this->assertLessThan(300, $response->status(), $call);
            $this->assertOnlyPublicIds($response->json(), $call);
        }
    }

    /**
     * `id`, `*_id`, `*_ids` and `assigned_to` hold a public id, a UUID or null; a `*_by` field (`uploaded_by` is the
     * uploader's name) is never an integer.
     *
     * @param  array<int|string, mixed>|null  $node
     */
    private function assertOnlyPublicIds(?array $node, string $where): void
    {
        foreach ($node ?? [] as $key => $value) {
            $at = $where.'.'.$key;

            if (is_string($key) && preg_match('/_by$/', $key) === 1) {
                $this->assertFalse(is_int($value), "{$at} is an integer: ".json_encode($value));

                continue;
            }

            if (is_string($key) && preg_match('/^(id|assigned_to)$|(_id|_ids)$/', $key) === 1) {
                foreach ((is_array($value) ? $value : [$value]) as $id) {
                    // The work queue keys an item `<domain>:<public id>`.
                    $name = $key === 'composite_id' && is_string($id) ? Str::after($id, ':') : $id;

                    $this->assertTrue(
                        $id === null || (is_string($name) && (Str::isUlid($name) || Str::isUuid($name))),
                        "{$at} is not a public id: ".json_encode($id),
                    );
                }

                continue;
            }

            if (is_array($value)) {
                $this->assertOnlyPublicIds($value, $at);
            }
        }
    }
}
