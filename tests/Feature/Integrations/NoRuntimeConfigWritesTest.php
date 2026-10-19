<?php

namespace Tests\Feature\Integrations;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Jobs\CancelMidtransTransactionJob;
use Modules\Billing\Models\PaymentAttempt;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NetworkAccount;
use PhpToken;
use Symfony\Component\Finder\Finder;
use Tests\Feature\WhatsApp\Fixtures\ProbeNotification;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;
use Tests\Traits\InteractsWithWhatsApp;

/**
 * A company's own Midtrans, WhatsApp, mail and RADIUS accounts are read from its `company_integrations` row, per job. The
 * config repository belongs to the whole process, so one runtime write for a provider (`config(['services.midtrans...'])`)
 * would outlive its job and hand one company's account to the next company's job in the same worker. The design documents
 * no exception: nothing under app/ or Modules/ writes these keys, and this guard keeps it that way. Seeders, commands run
 * by hand and the tests themselves are outside it.
 */
class NoRuntimeConfigWritesTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use InteractsWithMidtrans;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    /** The ways PHP code writes the config repository: the helper with an array, and the repository's setters. */
    private const WRITE_CALL = '/\bconfig\s*\(\s*\[|(?:\bConfig\s*::|\bconfig\s*\(\s*\)\s*->|\b(?:app|resolve)\s*\(\s*[\'"]config[\'"]\s*\)\s*->|\$config\s*->|->\s*config\s*->)\s*(?:set|prepend|push|offsetSet)\s*\(/';

    /**
     * The keys of a company's integration accounts, in a string literal of the write: the services of the three providers
     * (or `services` as a whole, which replaces them), every `mail` key, and the RADIUS connections (`radius` and `radius_{id}`).
     */
    private const GUARDED_KEY = '/[\'"](?:services(?:\.(?:midtrans|whatsapp|radius)|[\'"])|mail\b|database\.connections\.radius)/';

    private const KEY_A = 'SB-Mid-server-AAAA-key-of-company-a';

    private const KEY_A_ROTATED = 'SB-Mid-server-AAAA-key-saved-later';

    private const KEY_B = 'SB-Mid-server-BBBB-key-of-company-b';

    private const WA_NUMBER_A = '100000000000001';

    private const WA_NUMBER_B = '200000000000002';

    private const WA_TOKEN_A = 'wa-token-alpha';

    private const WA_TOKEN_A_ROTATED = 'wa-token-alpha-saved-later';

    private const WA_TOKEN_B = 'wa-token-beta';

    public function test_app_and_modules_never_write_config_at_runtime(): void
    {
        $writes = $this->guardedConfigWritesUnder([app_path(), base_path('Modules')]);

        $this->assertSame([], $writes, "Runtime write of a provider's config key (read the company's row with IntegrationConfig instead; a config write outlives its job and leaks into the next company's job):");
    }

    public function test_the_scan_flags_each_way_of_writing_an_integration_key_and_nothing_else(): void
    {
        $flagged = [
            "config(['services.midtrans.server_key' => \$key]);",
            'config(["services.whatsapp.access_token" => $token]);',
            "config(['services.radius.enabled' => true]);",
            "config(['mail.default' => 'array']);",
            "config(['mail.mailers.smtp.host' => \$host, 'mail.from.address' => \$from]);",
            "config(['services' => ['midtrans' => ['server_key' => \$key]]]);",
            "config(['database.connections.radius.host' => \$host]);",
            "config(['database.connections.radius_'.\$id.'.host' => \$host]);",
            "Config::set('services.midtrans.is_production', false);",
            "\\Illuminate\\Support\\Facades\\Config::set('mail.from.name', \$name);",
            "config()->set('services.whatsapp.graph_version', 'v1');",
            "app('config')->set('services.radius.coa_timeout', 1);",
            "\$config->set('mail.from.address', \$address);",
            "\$this->config->push('mail.mailers', 'x');",
        ];
        $allowed = [
            "\$key = config('services.midtrans.server_key');",
            "\$driver = config('mail.default', 'smtp');",
            "config(['app.timezone' => 'Asia/Jakarta']);",
            "config(['cache.default' => 'array', 'queue.default' => 'sync']);",
            "// config(['services.midtrans.server_key' => \$key]);",
            "/* Config::set('mail.default', 'log'); */",
            "/** config(['services.radius.enabled' => true]) in a doc comment */",
            "\$cache->set('mail.default', 1);",
        ];

        // One statement per line, an allowed line before each write, so the line of a write is its position in the file.
        $lines = ['<?php'];
        $expected = [];
        foreach ($flagged as $i => $write) {
            array_push($lines, $allowed[$i] ?? '', $write);
            $expected[] = 'Writes.php:'.count($lines);
        }
        // A write over several lines is reported at the line where it starts.
        $lines[] = "config([\n    'queue.default' => 'sync',\n    'mail.default' => 'array',\n]);";
        $expected[] = 'Writes.php:'.count($lines);

        $directory = sys_get_temp_dir().'/inbils-config-scan-'.Str::random(8);
        File::makeDirectory($directory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
        File::put($directory.'/Writes.php', implode("\n", $lines)."\n");
        // A file under a tests/ directory is not scanned: the tests set config on purpose.
        File::makeDirectory($directory.'/tests');
        File::put($directory.'/tests/Probe.php', "<?php\nconfig(['services.midtrans.server_key' => 'fake']);\n");

        $found = array_map(fn (string $write): string => Str::after($write, $directory.'/'), $this->guardedConfigWritesUnder([$directory]));

        $this->assertSame($expected, $found);
    }

    public function test_jobs_of_two_companies_in_one_worker_each_see_their_own_integrations(): void
    {
        config(['services.whatsapp.graph_version' => 'v25.0']);
        $this->fakeMidtrans();
        $this->fakeWhatsApp();
        $this->fakeRadius();
        $this->disconnectsSent();

        $a = $this->tenant('PT Alpha Net', self::KEY_A, self::WA_NUMBER_A, self::WA_TOKEN_A);
        $b = $this->tenant('CV Beta Net', self::KEY_B, self::WA_NUMBER_B, self::WA_TOKEN_B);
        $pages = ['a1' => $this->closedPageOf($a['company']), 'b1' => $this->closedPageOf($b['company']), 'a2' => $this->closedPageOf($a['company'])];
        $accountA = $this->accountOf($a['company'], 'alice');
        $accountB = $this->accountOf($b['company'], 'bob');

        // A real worker, the real database queue, one process: company A, company B, company A again, every provider.
        config(['queue.default' => 'database']);
        foreach ([[$a, 'a1'], [$b, 'b1'], [$a, 'a2']] as [$tenant, $page]) {
            CancelMidtransTransactionJob::dispatch($tenant['company']->id, $pages[$page]->id);
            Notification::send($tenant['customer'], new ProbeNotification($tenant['company']->id, dedupeKey: "probe:{$page}"));
        }
        $this->applyAccount($accountA);
        $this->applyAccount($accountB);
        $this->assertSame(8, DB::table('jobs')->count(), 'every job waits on the real queue');

        // After its first job of a kind, the admin of company A saves new keys through the web. That is another process:
        // no model event reaches this worker, so only a reset between jobs keeps A's second job from a stale account.
        $saved = [];
        Queue::after(function (JobProcessed $event) use (&$saved, $a): void {
            $job = $event->job->resolveName();

            if (isset($saved[$job])) {
                return;
            }

            $saved[$job] = true;
            CompanyIntegration::withoutEvents(match ($job) {
                CancelMidtransTransactionJob::class => fn () => $this->saveCredentials($a['company'], 'midtrans', ['server_key' => self::KEY_A_ROTATED]),
                ProbeNotification::class => fn () => $this->saveCredentials($a['company'], 'whatsapp', ['access_token' => self::WA_TOKEN_A_ROTATED]),
                default => fn () => null,
            });
        });

        Artisan::call('queue:work', ['--queue' => 'whatsapp,default', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertEqualsCanonicalizing([ProbeNotification::class, CancelMidtransTransactionJob::class, ApplyNetworkAccountJob::class], array_keys($saved), 'the worker ran all three kinds');

        // Midtrans: the cancel call of each page carries the key of the company that owns it, A's second one the key saved meanwhile.
        $cancelled = Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/cancel'))
            ->mapWithKeys(fn (Request $request): array => [Str::between($request->url(), '/v2/', '/cancel') => $request->header('Authorization')])
            ->all();
        $this->assertEquals([
            $pages['a1']->order_id => $this->basic(self::KEY_A),
            $pages['b1']->order_id => $this->basic(self::KEY_B),
            $pages['a2']->order_id => $this->basic(self::KEY_A_ROTATED),
        ], $cancelled);

        // WhatsApp: each message leaves from the number and token of its own company.
        $graph = 'https://graph.facebook.com/v25.0/';
        $this->assertEquals([
            [$graph.self::WA_NUMBER_A.'/messages', ['Bearer '.self::WA_TOKEN_A]],
            [$graph.self::WA_NUMBER_B.'/messages', ['Bearer '.self::WA_TOKEN_B]],
            [$graph.self::WA_NUMBER_A.'/messages', ['Bearer '.self::WA_TOKEN_A_ROTATED]],
        ], $this->graphRequests()->map(fn (Request $request): array => [$request->url(), $request->header('Authorization')])->all());
        $this->assertSame(['sent', 'sent'], $this->whatsAppRows($a['company']->id)->pluck('status')->all());
        $this->assertSame(['sent'], $this->whatsAppRows($b['company']->id)->pluck('status')->all());

        // RADIUS: each account was written to the database of its own company, and the shared template stayed empty.
        $this->assertSame(['alice@'.$this->realmOf($a['company'])], $this->usersIn($a['file']));
        $this->assertSame(['bob@'.$this->realmOf($b['company'])], $this->usersIn($b['file']));
        $this->assertSame([], $this->radiusRows('radcheck'));
        $this->assertSame(['synced', 'synced'], [$accountA->fresh()->sync_status, $accountB->fresh()->sync_status]);
    }

    /**
     * "path:line" of every runtime write of a provider's config key under the roots, tests/, vendor/ and node_modules/
     * left out. A statement is the text from the write up to the next `;`.
     *
     * shortcut: a write whose key is built from a variable alone ($key => ...) cannot be seen here; every key of the three
     * providers has a literal prefix today, so none is missed.
     *
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function guardedConfigWritesUnder(array $roots): array
    {
        $found = [];
        $files = Finder::create()->files()->in($roots)->name('*.php')->notPath('#(^|/)(tests|vendor|node_modules)/#');

        foreach ($files as $file) {
            $code = $this->withoutComments($file->getContents());
            preg_match_all(self::WRITE_CALL, $code, $writes, PREG_OFFSET_CAPTURE);

            foreach ($writes[0] as [, $offset]) {
                $statement = substr($code, $offset, strcspn($code, ';', $offset));

                if (preg_match(self::GUARDED_KEY, $statement) === 1) {
                    $found[] = Str::after($file->getRealPath(), base_path().'/').':'.(substr_count($code, "\n", 0, $offset) + 1);
                }
            }
        }

        return $found;
    }

    /** The code with every comment blanked and every line kept, so an offset still maps to its line. */
    private function withoutComments(string $code): string
    {
        return implode('', array_map(
            fn (PhpToken $token): string => $token->is([T_COMMENT, T_DOC_COMMENT]) ? str_repeat("\n", substr_count($token->text, "\n")) : $token->text,
            PhpToken::tokenize($code),
        ));
    }

    /** @return array{company: Company, customer: Customer, file: string} a company with its own Midtrans, WhatsApp and RADIUS account */
    private function tenant(string $name, string $midtransKey, string $whatsappNumber, string $whatsappToken): array
    {
        $company = $this->whatsAppEnabledFor(Company::factory()->create(['name' => $name, 'is_active' => true]));
        $file = $this->newRadiusDatabase();

        $this->midtransIntegration($company, $midtransKey);
        $this->whatsAppIntegration($company, $whatsappNumber, ['access_token' => $whatsappToken]);
        $this->radiusIntegration($company, $file);

        return ['company' => $company, 'customer' => $this->consentedCustomer($company), 'file' => $file];
    }

    /** A payment page the company closed, whose transaction still has to be cancelled at Midtrans. */
    private function closedPageOf(Company $company): PaymentAttempt
    {
        return CompanyService::runAs($company->id, function () use ($company): PaymentAttempt {
            $invoice = InvoiceFactory::new()->create([
                'company_id' => $company->id,
                'customer_id' => Customer::factory()->create(['company_id' => $company->id])->id,
                'status' => 'sent',
                'total' => 100000,
            ]);

            return PaymentAttemptFactory::new()->forInvoice($invoice)->status(PaymentAttempt::STATUS_CANCELLED_LOCALLY)->create();
        });
    }

    private function accountOf(Company $company, string $local): NetworkAccount
    {
        $subscription = $this->subscriptionIn($company, 'active', null, $this->packageWithRadiusProfile($company, 'home-20m'));

        return $this->networkAccountFor($subscription, ['username' => $local.'@'.$this->realmOf($company)]);
    }

    /** @param  array<string, string>  $credentials */
    private function saveCredentials(Company $company, string $provider, array $credentials): void
    {
        $row = CompanyIntegration::withoutCompany()->where('company_id', $company->id)->where('provider', $provider)->firstOrFail();
        $row->update(['credentials' => [...$row->credentials, ...$credentials]]);
    }

    /** @return list<string> */
    private function usersIn(string $file): array
    {
        return $this->radiusConnectionTo($file)->table('radcheck')->orderBy('id')->pluck('username')->all();
    }

    /** @return list<string> */
    private function basic(string $key): array
    {
        return ['Basic '.base64_encode($key.':')];
    }
}
