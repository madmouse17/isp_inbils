<?php

namespace Tests\Feature\Api\V1\Matrix;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;

/**
 * Walks every GET route (and the payment call) as the owner, over a dataset that has a value planted in every
 * field the apps must never receive, and fails on a forbidden key or a planted value anywhere in a response.
 */
class SecretKeyWalkTest extends TestCase
{
    use BuildsMatrixWorld;
    use RefreshDatabase;

    /** @var list<string> */
    private const NEVER_SENT = [
        'password', 'remember_token', 'snap_token', 'order_id', 'last_notification', 'received_by',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_used_step',
        'network_accounts', 'pppoe_password', 'token', 'plain_text_token', 'abilities',
    ];

    /**
     * A customer reads the login name of a subscription as `pppoe_username`; the `network_account` block belongs to the
     * technician an installation SPK is assigned to, and even there it holds a name and a state, never what opens it.
     *
     * @var list<string>
     */
    private const NEVER_SENT_TO_CUSTOMERS = ['tax_id', 'notes', 'is_internal', 'network_account'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
    }

    public function test_no_get_response_of_either_app_carries_a_secret_key_or_value(): void
    {
        $walked = 0;

        foreach ($this->apiRoutes() as $route) {
            if ($this->method($route) !== 'GET' || in_array($this->shortName($route), ['media.show', 'customer.invoices.receipt'], true)) {
                continue; // media and the receipt stream a file, checked by MediaTest and InvoicesTest
            }

            $technician = $this->fenceOf($route) === 'technician';
            $response = $this->api('GET', $this->urlOf($route, real: true), token: $technician ? $this->technicianToken : $this->customerToken)->assertOk();

            $this->assertClean($response->json(), $route->uri(), $technician);
            $this->assertAccountBlockHoldsNoSecret($response->json(), $route->uri());
            $this->assertValuesClean($response->getContent(), $route->uri());
            if (! $technician) {
                $this->assertStringNotContainsString(self::SECRET_TECHNICIAN_PHONE, $response->getContent(), $route->uri().' tells a customer the technician\'s phone');
            }
            $walked++;
        }

        $this->assertSame(25, $walked, 'a GET route was added or removed');
    }

    public function test_the_technician_sees_the_login_name_and_state_of_an_installation_never_its_secret(): void
    {
        WorkOrder::withoutCompany()->wherePublicId($this->ids['customer_work_order'])->update(['type' => 'installation']);

        $response = $this->api('GET', 'technician/work-orders/'.$this->ids['customer_work_order'], token: $this->technicianToken)->assertOk();

        $this->assertClean($response->json(), 'technician work order', true);
        $this->assertValuesClean($response->getContent(), 'technician work order');
        $this->assertSame(
            ['username' => NetworkAccount::withoutCompany()->where('subscription_id', $this->subscription->id)->value('username'), 'sync_status' => 'pending', 'can_reveal' => true],
            $response->json('data.network_account'),
        );
    }

    public function test_the_payment_call_and_the_ticket_a_customer_reads_leak_nothing_either(): void
    {
        $pay = $this->api('POST', 'customer/invoices/'.$this->ids['invoice'].'/pay', token: $this->customerToken);

        $this->assertLessThan(500, $pay->status());
        $this->assertClean($pay->json(), 'pay', false);
        $this->assertValuesClean($pay->getContent(), 'pay');

        $ticket = $this->api('GET', 'customer/tickets/'.$this->ids['customer_ticket'], token: $this->customerToken)->assertOk();
        $this->assertSame(['Public reply'], collect($ticket->json('data.comments'))->pluck('body')->all(), 'a customer reads public comments only');
    }

    /** @param  array<string, mixed>|null  $payload */
    private function assertClean(?array $payload, string $where, bool $technician): void
    {
        $forbidden = $technician ? self::NEVER_SENT : [...self::NEVER_SENT, ...self::NEVER_SENT_TO_CUSTOMERS];

        array_walk_recursive_keys($payload ?? [], function (string $key) use ($forbidden, $where): void {
            $this->assertNotContains($key, $forbidden, "{$where} sends the key {$key}");
        });
    }

    /** @param  array<string, mixed>|null  $payload */
    private function assertAccountBlockHoldsNoSecret(?array $payload, string $where): void
    {
        $account = $payload['data']['network_account'] ?? null;

        if (is_array($account)) {
            $keys = array_keys($account);
            sort($keys);
            $this->assertSame(['can_reveal', 'sync_status', 'username'], $keys, "{$where} sends more of the network account than its login name and state");
        }
    }

    private function assertValuesClean(string $body, string $where): void
    {
        foreach ([self::SECRET_SNAP, self::SECRET_NOTE, self::SECRET_INTERNAL_COMMENT, self::SECRET_PPPOE, self::SECRET_TAX, self::SECRET_TWO_FACTOR] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "{$where} sends a planted secret");
        }
    }
}

/** @param  array<int|string, mixed>  $data */
function array_walk_recursive_keys(array $data, callable $visit): void
{
    foreach ($data as $key => $value) {
        if (is_string($key)) {
            $visit($key);
        }

        if (is_array($value)) {
            array_walk_recursive_keys($value, $visit);
        }
    }
}
