<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\V1\PostsMultipart;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * F6-D14, F6-D15: a ticket takes a photo or a PDF, from the web and from the apps alike, with a caption of the uploader's own
 * that the page and the apps show next to who added the file and when.
 */
class TicketAttachmentRulesTest extends TestCase
{
    use InteractsWithMobileApi;
    use PostsMultipart;
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

    private User $customerUser;

    private Customer $customer;

    private string $token;

    private User $staff;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        [$this->customerUser, $this->customer] = $this->customerLogin();
        $this->customerUser->forceFill(['name' => 'Sari Wulandari'])->save();
        $this->token = $this->issueToken($this->customerUser, 'customer');
        $this->staff = User::factory()->create(['company_id' => $this->customerUser->company_id, 'email_verified_at' => now(), 'is_active' => true, 'name' => 'Rina Manajer']);
        $this->grantRole($this->staff, 'admin');
        $category = TicketCategory::query()->create([
            'company_id' => $this->customerUser->company_id, 'name' => 'Support', 'code' => 'SUP-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
        $this->ticket = Ticket::query()->create([
            'company_id' => $this->customerUser->company_id, 'code' => 'TKT-ATT', 'title' => 'Internet mati', 'source' => 'customer', 'category_id' => $category->id,
            'status' => 'assigned', 'priority' => 'medium', 'customer_id' => $this->customer->id, 'assigned_to' => $this->staff->id,
            'created_by' => $this->customerUser->id, 'sla_deadline' => now()->addDay(),
        ]);
    }

    /** @return array<string, array{0: callable(): UploadedFile}> */
    public static function accepted(): array
    {
        return [
            'jpg' => [fn (): UploadedFile => UploadedFile::fake()->image('modem.jpg')],
            'jpeg' => [fn (): UploadedFile => UploadedFile::fake()->image('modem.jpeg')],
            'png' => [fn (): UploadedFile => UploadedFile::fake()->image('modem.png')],
            'pdf' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('kontrak.pdf', self::PDF)],
        ];
    }

    /** @return array<string, array{0: callable(): UploadedFile}> */
    public static function refused(): array
    {
        return [
            'word' => [fn (): UploadedFile => UploadedFile::fake()->create('catatan.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
            'old word' => [fn (): UploadedFile => UploadedFile::fake()->create('catatan.doc', 10, 'application/msword')],
            'text' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('catatan.txt', 'teks biasa')],
            // A real upload, so its content is read: a faked file is judged by its name alone.
            'a script named like a pdf' => [static function (): UploadedFile {
                $path = (string) tempnam(sys_get_temp_dir(), 'att');
                file_put_contents($path, '<?php echo "x";');

                return new UploadedFile($path, 'bukti.pdf', 'application/pdf', null, true);
            }],
            'a pdf named like a text' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('bukti.txt', self::PDF)],
        ];
    }

    /** @param  callable(): UploadedFile  $file */
    #[DataProvider('accepted')]
    public function test_the_web_takes_a_photo_or_a_pdf_with_its_caption(callable $file): void
    {
        $this->actingAs($this->staff)
            ->post(route('admin.tickets.attachments.store', $this->ticket), ['file' => $file(), 'caption' => 'Lampu LOS merah'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $media = $this->ticket->fresh()->getMedia('attachments')->sole();
        $this->assertSame('Lampu LOS merah', $media->getCustomProperty('caption'));
        $this->assertSame($this->staff->id, $media->getCustomProperty('uploaded_by'));
    }

    /** @param  callable(): UploadedFile  $file */
    #[DataProvider('refused')]
    public function test_the_web_refuses_the_rest(callable $file): void
    {
        $this->actingAs($this->staff)
            ->post(route('admin.tickets.attachments.store', $this->ticket), ['file' => $file()])
            ->assertSessionHasErrors('file');

        $this->assertCount(0, $this->ticket->fresh()->getMedia('attachments'));
    }

    /** @param  callable(): UploadedFile  $file */
    #[DataProvider('refused')]
    public function test_the_apps_refuse_the_same_files(callable $file): void
    {
        $this->multipart("customer/tickets/{$this->ticket->public_id}/attachments", ['file' => $file()], $this->token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertCount(0, $this->ticket->fresh()->getMedia('attachments'));
    }

    public function test_the_caption_is_optional_and_holds_two_hundred_characters(): void
    {
        // Two different pictures: the same file twice on one ticket is one attachment.
        $upload = fn (array $extra, int $side): TestResponse => $this->actingAs($this->staff)
            ->post(route('admin.tickets.attachments.store', $this->ticket), ['file' => UploadedFile::fake()->image("foto-{$side}.jpg", $side, $side), ...$extra]);

        $upload(['caption' => str_repeat('a', 201)], 20)->assertSessionHasErrors('caption');
        $this->assertCount(0, $this->ticket->fresh()->getMedia('attachments'));

        $upload(['caption' => str_repeat('a', 200)], 21)->assertSessionHasNoErrors();
        $upload([], 22)->assertSessionHasNoErrors();

        $captions = $this->ticket->fresh()->getMedia('attachments')->map(fn ($media): mixed => $media->getCustomProperty('caption'))->all();
        $this->assertSame([str_repeat('a', 200), null], $captions);
    }

    public function test_a_file_over_ten_megabytes_is_still_refused(): void
    {
        $this->actingAs($this->staff)
            ->post(route('admin.tickets.attachments.store', $this->ticket), ['file' => UploadedFile::fake()->create('besar.pdf', 10241, 'application/pdf')])
            ->assertSessionHasErrors('file');
    }

    public function test_the_page_lists_each_file_with_its_caption_and_who_added_it(): void
    {
        $this->actingAs($this->staff)
            ->post(route('admin.tickets.attachments.store', $this->ticket), ['file' => UploadedFile::fake()->image('modem.jpg'), 'caption' => 'Lampu LOS merah'])
            ->assertRedirect();

        $file = $this->get(route('admin.tickets.show', $this->ticket))->assertOk()->viewData('page')['props']['ticket']['data']['attachments'][0];

        $this->assertSame('Lampu LOS merah', $file['caption']);
        $this->assertSame($this->staff->public_id, $file['uploaded_by']);
        $this->assertNotNull($file['uploaded_at']);
    }

    public function test_the_apps_see_the_caption_the_time_and_the_first_name_of_whoever_added_each_file(): void
    {
        $this->actingAs($this->staff)
            ->post(route('admin.tickets.attachments.store', $this->ticket), ['file' => UploadedFile::fake()->image('dari-kantor.jpg'), 'caption' => 'Foto dari teknisi'])
            ->assertRedirect();
        app('auth')->forgetGuards();

        $this->multipart("customer/tickets/{$this->ticket->public_id}/attachments", ['file' => UploadedFile::fake()->image('modem.jpg'), 'caption' => 'Lampu merah'], $this->token)
            ->assertCreated()
            ->assertJsonPath('data.caption', 'Lampu merah')
            ->assertJsonPath('data.uploader_first_name', 'Sari')
            ->assertJsonPath('data.mine', true);

        $files = collect($this->api('GET', "customer/tickets/{$this->ticket->public_id}", token: $this->token)->assertOk()->json('data.attachments'))->keyBy('caption');

        $this->assertSame('Rina', $files['Foto dari teknisi']['uploader_first_name']);
        $this->assertFalse($files['Foto dari teknisi']['mine']);
        $this->assertSame('Sari', $files['Lampu merah']['uploader_first_name']);
        foreach ($files as $file) {
            $this->assertNotNull($file['uploaded_at']);
            $this->assertArrayNotHasKey('uploaded_by', $file, 'only a first name leaves the building, never an id or an email');
            $this->assertStringNotContainsString('@', (string) json_encode($file));
        }
    }

    public function test_the_uploaders_of_a_ticket_come_in_one_query_however_many_files_there_are(): void
    {
        $others = User::factory()->count(5)->create(['company_id' => $this->customerUser->company_id, 'is_active' => true]);
        $attach = fn (User $user) => $this->ticket->addMedia(UploadedFile::fake()->image('f.jpg'))->withCustomProperties(['uploaded_by' => $user->id])->toMediaCollection('attachments');
        $cost = function (): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->api('GET', "customer/tickets/{$this->ticket->public_id}", token: $this->token)->assertOk();
            // Sanctum notes when a token was last used, at most once a minute: not a cost of the page.
            $queries = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => ! str_contains($sql, 'personal_access_tokens')));
            DB::disableQueryLog();

            return $queries;
        };

        $attach($others[0]);
        $cost(); // the first request also warms the permission cache
        $one = $cost();
        foreach ($others->slice(1) as $user) {
            $attach($user);
        }
        $many = $cost();

        $this->assertCount(count($one), $many, 'five files by five people cost what one file costs: the uploaders come in one query');
    }
}
