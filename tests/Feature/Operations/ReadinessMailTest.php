<?php

namespace Tests\Feature\Operations;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * K22: `/ready` says whether the sender of the platform mail is still a placeholder. Only production is judged; the answer
 * never repeats the address.
 */
class ReadinessMailTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function senders(): array
    {
        return [
            'the old .env.example default' => ['hello@example.com', 'fail'],
            'a subdomain of example.com' => ['no-reply@mail.example.com', 'fail'],
            'the new .env.example default' => ['no-reply@inbils.local', 'fail'],
            'no address at all' => ['', 'fail'],
            'a real address' => ['no-reply@inbils.co.id', 'ok'],
        ];
    }

    #[DataProvider('senders')]
    public function test_a_production_sender_is_judged_by_its_domain(string $address, string $expected): void
    {
        $this->app['env'] = 'production';
        config(['mail.from.address' => $address]);

        $response = $this->getJson('/ready')->assertOk()->assertJsonPath('checks.mail', $expected);

        if ($address !== '') {
            $this->assertStringNotContainsString($address, (string) $response->getContent(), 'the address stays on the server');
        }
    }

    public function test_a_failing_sender_degrades_readiness(): void
    {
        $this->app['env'] = 'production';
        config(['mail.from.address' => 'hello@example.com']);

        $this->getJson('/ready')->assertJsonPath('status', 'degraded');
    }

    public function test_other_environments_are_not_judged(): void
    {
        config(['mail.from.address' => 'hello@example.com']);

        $this->assertTrue(app()->environment('testing'));
        $this->getJson('/ready')->assertJsonPath('checks.mail', 'unknown');
    }
}
