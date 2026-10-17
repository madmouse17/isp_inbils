<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Service\Database\Factories\ServicePackageFactory;

/**
 * Fake Cloud API credentials, a fake Graph endpoint and Meta-shaped webhook payloads for tests. None of the
 * values below is real; the real ones live only in the host .env and are never read by a test.
 */
trait InteractsWithWhatsApp
{
    protected const WA_TOKEN = 'wa-FAKE-access-token';

    protected const WA_APP_SECRET = 'wa-FAKE-app-secret';

    protected const WA_VERIFY_TOKEN = 'wa-FAKE-verify-token';

    protected const WA_PHONE_NUMBER_ID = '106540352242922';

    /** @var (Closure(Request): mixed)|null answers the Graph send call; null = accepted with a fresh wamid */
    protected ?Closure $whatsAppReply = null;

    protected function enableWhatsApp(): void
    {
        config([
            'services.whatsapp.access_token' => self::WA_TOKEN,
            'services.whatsapp.phone_number_id' => self::WA_PHONE_NUMBER_ID,
            'services.whatsapp.business_account_id' => '102290129340398',
            'services.whatsapp.app_secret' => self::WA_APP_SECRET,
            'services.whatsapp.webhook_verify_token' => self::WA_VERIFY_TOKEN,
            'services.whatsapp.graph_version' => 'v25.0',
        ]);
    }

    protected function disableWhatsApp(): void
    {
        config(['services.whatsapp.access_token' => null, 'services.whatsapp.phone_number_id' => null]);
    }

    /**
     * Fake the Graph send endpoint once per test and refuse any other outbound call. `Http::fake()` keeps the
     * first stub that matches, so tests change the answer through $whatsAppReply, never by faking again.
     */
    protected function fakeWhatsApp(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*' => fn (Request $request) => ($this->whatsAppReply ?? fn (Request $r) => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => $r->data()['to'], 'wa_id' => $r->data()['to']]],
                'messages' => [['id' => 'wamid.'.Str::random(24), 'message_status' => 'accepted']],
            ]))($request),
        ]);
    }

    /** @param  array<string, mixed>  $body */
    protected function whatsAppReplies(int $status, array $body): void
    {
        $this->whatsAppReply = fn () => Http::response($body, $status);
    }

    /** @return Collection<int, Request> every call made to the Graph API so far (other fakes, like Midtrans, are not counted) */
    protected function graphRequests(): Collection
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_starts_with($request->url(), 'https://graph.facebook.com/'))
            ->values();
    }

    /** A customer of the company who agreed to WhatsApp messages and has a valid mobile number. */
    protected function consentedCustomer(Company|int $company, string $phone = '0812-3456-7890'): Customer
    {
        $customer = Customer::factory()->create([
            'company_id' => $company instanceof Company ? $company->id : $company,
            'phone' => $phone,
            'is_active' => true,
        ]);
        $customer->forceFill(['whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_source' => 'staff'])->save();

        return $customer;
    }

    /** Turn the feature on for the company with every event enabled (the default), unless told otherwise. */
    protected function whatsAppEnabledFor(Company $company, array $settings = []): Company
    {
        $company->forceFill(['settings' => array_merge($company->settings ?? [], ['whatsapp_enabled' => true], $settings)])->save();

        return $company;
    }

    protected function technician(Company $company, string $phone = '0813-5555-0001', string $profileStatus = 'active'): User
    {
        $user = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'phone' => $phone,
            'status' => $profileStatus,
        ]);

        return $user;
    }

    /**
     * A valid body for admin.customers.store with its own service package, POP and Jakarta region rows
     * (customers are onboarded with a subscription, two addresses and a contact).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function customerOnboardingPayload(Company $company, array $overrides = []): array
    {
        $package = ServicePackageFactory::new()->create(['company_id' => $company->id, 'price_mrc' => 250000, 'price_otc' => 150000, 'contract_min_months' => 12]);
        $location = Location::query()->create(['company_id' => $company->id, 'code' => 'POP-'.$package->id, 'name' => 'POP Onboarding', 'type' => 'pop', 'path' => 'POP Onboarding', 'is_active' => true]);
        DB::table('indonesia_provinces')->insertOrIgnore(['code' => '31', 'name' => 'DKI JAKARTA']);
        DB::table('indonesia_cities')->insertOrIgnore(['code' => '3171', 'province_code' => '31', 'name' => 'KOTA JAKARTA SELATAN']);
        DB::table('indonesia_districts')->insertOrIgnore(['code' => '3171010', 'city_code' => '3171', 'name' => 'KEBAYORAN BARU']);
        DB::table('indonesia_villages')->insertOrIgnore(['code' => '3171010001', 'district_code' => '3171010', 'name' => 'GANDARIA UTARA']);

        return [
            'code' => 'CUS-WA',
            'name' => 'Pelanggan WhatsApp',
            'type' => 'Individual',
            'email' => 'wa.customer@example.test',
            'phone' => '0812-3456-7890',
            'is_active' => true,
            'addresses' => [
                ['label' => 'Main', 'address' => 'Jl. Utama 1', 'city' => 'Jakarta', 'postal_code' => '12345', 'province_code' => '31', 'city_code' => '3171', 'district_code' => '3171010', 'village_code' => '3171010001', 'lat' => -6.2, 'lng' => 106.8166667, 'is_primary' => true, 'is_installation_point' => false, 'notes' => null],
                ['label' => 'Installation', 'address' => 'Jl. Pasang 2', 'city' => 'Jakarta', 'postal_code' => '12346', 'province_code' => '31', 'city_code' => '3171', 'district_code' => '3171010', 'village_code' => '3171010001', 'lat' => -6.201, 'lng' => 106.817, 'is_primary' => false, 'is_installation_point' => true, 'notes' => null],
            ],
            'contacts' => [
                ['name' => 'Kontak Utama', 'position' => 'Owner', 'phone' => '0812-3456-7890', 'email' => 'kontak.utama@example.test', 'is_primary' => true, 'notes' => null],
            ],
            'subscription' => ['service_package_id' => $package->public_id, 'serving_pop_id' => $location->public_id, 'billing_day' => 5, 'mrc_amount' => null, 'otc_installation_fee' => null, 'contract_months' => null, 'notes' => null],
            ...$overrides,
        ];
    }

    /** @return Collection<int, WhatsAppMessage> */
    protected function whatsAppRows(?int $companyId = null): Collection
    {
        $rows = WhatsAppMessage::withoutCompany()->orderBy('id');

        return ($companyId === null ? $rows : $rows->where('company_id', $companyId))->get();
    }

    /**
     * A signed webhook call the way Meta makes it: raw JSON body, HMAC-SHA256 of that exact body with the app secret.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, string>}
     */
    protected function signedWebhook(array $payload, string $secret = self::WA_APP_SECRET): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return [$body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $statuses
     * @return array<string, mixed>
     */
    protected function statusWebhook(array $statuses): array
    {
        return $this->webhookEnvelope(['statuses' => $statuses]);
    }

    /**
     * An inbound text message the way Meta delivers it. The id and the unix time default to a fresh wamid and now,
     * and are given explicitly to replay or to age a message.
     *
     * @return array<string, mixed>
     */
    protected function textWebhook(string $from, string $body, ?string $id = null, ?int $timestamp = null): array
    {
        return $this->webhookEnvelope([
            'contacts' => [['profile' => ['name' => 'Someone'], 'wa_id' => $from]],
            'messages' => [[
                'from' => $from,
                'id' => $id ?? 'wamid.'.Str::random(24),
                'timestamp' => (string) ($timestamp ?? now()->timestamp),
                'type' => 'text',
                'text' => ['body' => $body],
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function webhookEnvelope(array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '102290129340398',
                'changes' => [[
                    'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::WA_PHONE_NUMBER_ID]] + $value,
                    'field' => 'messages',
                ]],
            ]],
        ];
    }
}
