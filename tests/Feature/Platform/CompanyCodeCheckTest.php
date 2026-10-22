<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F1-U02: the new-company form asks whether a code is free before it is sent. */
class CompanyCodeCheckTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_it_says_whether_a_code_is_taken(): void
    {
        $this->buildTenant('ALP', 'Alpha Net');

        $this->asOperator(false)->getJson(route('platform.companies.code-check', ['code' => 'ALP']))
            ->assertOk()->assertExactJson(['available' => false]);
        $this->asOperator(false)->getJson(route('platform.companies.code-check', ['code' => 'ALP-C']))
            ->assertOk()->assertExactJson(['available' => false]);
        $this->asOperator(false)->getJson(route('platform.companies.code-check', ['code' => 'NEW-CO']))
            ->assertOk()->assertExactJson(['available' => true]);
    }

    public function test_a_code_the_form_would_refuse_is_a_validation_error(): void
    {
        foreach (['-bad', 'has space', str_repeat('A', 51), ''] as $code) {
            $this->asOperator(false)->getJson(route('platform.companies.code-check', ['code' => $code]))
                ->assertStatus(422)->assertJsonValidationErrors('code');
        }
    }

    public function test_only_a_signed_in_operator_may_ask(): void
    {
        $this->getJson(route('platform.companies.code-check', ['code' => 'NEW-CO']))->assertUnauthorized();
    }
}
