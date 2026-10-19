<?php

namespace Tests\Feature\PublicId;

use App\Models\Core\EmployeeEvaluation;
use App\Services\Core\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\InteractsWithMobileApi;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Spec .scratch/opaque-ids K3, for a client that asks the web for JSON (an axios call, curl): a record that does not
 * exist, is another company's, or that the policy hides gets the same body, with no model class and no route in it.
 * The mobile API keeps its own `{message, code}` envelope.
 */
class UniformNotFoundJsonTest extends TestCase
{
    use BuildsCompanyGroup;
    use InteractsWithMobileApi;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
    }

    public function test_a_missing_foreign_or_hidden_record_answers_the_same_json_on_a_web_route(): void
    {
        $admin = $this->groupUser($this->head);
        $technician = $this->groupUser($this->head, 'technician');
        $colleague = $this->groupUser($this->head, 'technician');
        $hidden = EmployeeEvaluation::factory()->create(['company_id' => $this->head->id, 'employee_id' => $colleague->id, 'evaluator_id' => $admin->id]);
        $foreign = EmployeeEvaluation::factory()->create(['company_id' => $this->outsider->id, 'employee_id' => $colleague->id, 'evaluator_id' => $admin->id]);

        $this->actingAs($technician);

        $urls = [
            'unknown id' => route('admin.evaluations.show', strtolower((string) Str::ulid())),
            'another company' => route('admin.evaluations.show', $foreign),
            'hidden by the policy' => route('admin.evaluations.show', $hidden),
            'integer id' => '/admin/evaluations/'.$hidden->id,
            'unknown url' => '/admin/there-is-no-such-page',
        ];

        foreach ($urls as $case => $url) {
            $response = $this->getJson($url);

            $this->assertSame(404, $response->getStatusCode(), $case);
            $this->assertSame(['message' => 'Not Found'], $response->json(), $case);
        }
    }

    public function test_the_mobile_api_keeps_its_own_404_body(): void
    {
        [$user] = $this->customerLogin($this->head);

        $response = $this->api('GET', 'customer/subscriptions/'.strtolower((string) Str::ulid()), token: $this->issueToken($user, ApiTokenService::CUSTOMER))
            ->assertNotFound();

        $response->assertExactJson(['message' => 'Not found.', 'code' => 'not_found', 'request_id' => $response->headers->get('X-Request-Id')]);
    }
}
