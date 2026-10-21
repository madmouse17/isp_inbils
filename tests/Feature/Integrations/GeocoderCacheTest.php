<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use App\Services\Core\OpenStreetMapGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The address search keeps an hour of answers, per company: a cache shared by all of them answers at once while a miss takes a
 * round trip, so one company could tell by the time it waits whether another searched the same address. A failed lookup is
 * never kept: it says nothing about the address, and an hour of "nothing found" would hide it from every retry.
 */
class GeocoderCacheTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Jalan Merdeka 12 Bandung';

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml logs to stderr: the failure warning is not the point here.
        config(['logging.default' => 'null']);
    }

    /** @return array<string, mixed> */
    private function place(): array
    {
        return ['display_name' => 'Jalan Merdeka 12, Bandung', 'lat' => '-6.9', 'lon' => '107.6', 'address' => ['road' => 'Jalan Merdeka', 'house_number' => '12', 'postcode' => '40111']];
    }

    /** @return array<int, array<string, string>> */
    private function searchAs(Company $company, string $query = self::QUERY): array
    {
        return CompanyService::runAs($company->id, fn (): array => app(OpenStreetMapGeocoder::class)->search($query));
    }

    public function test_each_company_has_its_own_cache(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([$this->place()])]);
        $a = Company::factory()->create();
        $b = Company::factory()->create();

        $this->assertSame(['Jalan Merdeka 12'], array_column($this->searchAs($a), 'street'));
        $this->searchAs($a);
        Http::assertSentCount(1);

        $this->assertSame(['Jalan Merdeka 12'], array_column($this->searchAs($b), 'street'));
        Http::assertSentCount(2);

        $this->searchAs($b);
        Http::assertSentCount(2);
    }

    public function test_a_failed_lookup_is_not_kept_and_the_retry_asks_again(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()->push([], 503)->push([$this->place()])]);
        $company = Company::factory()->create();

        $this->assertSame([], $this->searchAs($company));
        $retry = $this->searchAs($company);

        $this->assertSame(['Jalan Merdeka 12'], array_column($retry, 'street'));
        Http::assertSentCount(2);
    }

    public function test_an_answer_of_nothing_found_is_kept_like_any_other(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
        $company = Company::factory()->create();

        $this->assertSame([], $this->searchAs($company));
        $this->assertSame([], $this->searchAs($company));

        Http::assertSentCount(1);
    }
}
