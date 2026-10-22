<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyLogoService;
use App\Services\Core\DocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\WithoutAppOnlyRedirect;

class PrivateMediaAccessTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    /** A 1x1 grey PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private User $admin;

    private User $owner;

    private Ticket $ticket;

    private WorkOrder $workOrder;

    private Customer $customer;

    private Media $ticketFile;

    private Media $foreignTicketFile;

    private Media $evidenceFile;

    private Media $documentFile;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->admin = $this->createCompanyUser();
        [$this->owner, $ownCustomer] = $this->portalUser();
        [, $otherCustomer] = $this->portalUser();
        $category = TicketCategory::query()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        $this->customer = $ownCustomer;
        $this->ticket = $this->makeTicket($category, $ownCustomer->id);
        $foreignTicket = $this->makeTicket($category, $otherCustomer->id);
        $this->workOrder = WorkOrder::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'SPK-'.now()->year.'-'.fake()->unique()->numberBetween(10000, 99999),
            'type' => 'maintenance',
            'title' => 'Evidence SPK',
            'status' => 'draft',
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
        ]);

        $this->ticketFile = $this->store($this->ticket, 'attachments', 'site-photo.png', base64_decode(self::PNG));
        $this->foreignTicketFile = $this->store($foreignTicket, 'attachments', 'other.png', base64_decode(self::PNG));
        $this->evidenceFile = $this->store($this->workOrder, 'evidence', 'proof.png', base64_decode(self::PNG));
        $this->documentFile = $this->store($this->customer, 'documents', 'contract.pdf', self::PDF);
    }

    /** @return array<string, array{0: string}> */
    public static function ownerTypes(): array
    {
        return [
            'ticket attachment' => ['ticketFile'],
            'SPK evidence' => ['evidenceFile'],
            'customer document' => ['documentFile'],
        ];
    }

    #[DataProvider('ownerTypes')]
    public function test_guest_is_sent_to_login(string $file): void
    {
        $this->get(route('admin.media.show', $this->{$file}))->assertRedirect(route('login'));
    }

    #[DataProvider('ownerTypes')]
    public function test_user_of_another_company_gets_404(string $file): void
    {
        $this->actingAs($this->outsider())
            ->get(route('admin.media.show', $this->{$file}))
            ->assertNotFound();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function dispositions(): array
    {
        return [
            'ticket attachment (PNG)' => ['ticketFile', 'inline'],
            'SPK evidence (PNG)' => ['evidenceFile', 'inline'],
            'customer document (PDF)' => ['documentFile', 'attachment'],
        ];
    }

    #[DataProvider('dispositions')]
    public function test_staff_of_the_same_company_gets_the_file(string $file, string $disposition): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.media.show', $this->{$file}));

        $response->assertOk();
        $this->assertSame(
            Storage::disk('local')->get($this->{$file}->getPathRelativeToRoot()),
            $response->streamedContent(),
        );
        $this->assertSame($this->{$file}->mime_type, $response->headers->get('Content-Type'));
        $this->assertStringStartsWith($disposition.';', (string) $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    #[DataProvider('ownerTypes')]
    public function test_staff_without_the_permission_gets_403(string $file): void
    {
        $nobody = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($nobody)
            ->get(route('admin.media.show', $this->{$file}))
            ->assertForbidden();
    }

    public function test_customer_gets_the_attachments_of_their_own_ticket(): void
    {
        $response = $this->actingAs($this->owner)->get(route('admin.media.show', $this->ticketFile));

        $response->assertOk();
        $this->assertSame(base64_decode(self::PNG), $response->streamedContent());
    }

    public function test_customer_gets_404_for_another_customers_attachment(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.media.show', $this->foreignTicketFile))
            ->assertNotFound();
    }

    #[DataProvider('staffOnlyTypes')]
    public function test_customer_gets_403_for_spk_evidence_and_customer_documents(string $file): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.media.show', $this->{$file}))
            ->assertForbidden();
    }

    /** @return array<string, array{0: string}> */
    public static function staffOnlyTypes(): array
    {
        return [
            'SPK evidence' => ['evidenceFile'],
            'customer document' => ['documentFile'],
        ];
    }

    public function test_unknown_media_is_404(): void
    {
        $this->actingAs($this->admin)->get('/admin/media/999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/media/abc')->assertNotFound();
    }

    public function test_media_of_a_deleted_owner_is_404(): void
    {
        $this->ticket->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.media.show', $this->ticketFile))
            ->assertNotFound();
    }

    public function test_media_whose_file_is_gone_is_404(): void
    {
        Storage::disk('local')->delete($this->ticketFile->getPathRelativeToRoot());

        $this->actingAs($this->admin)
            ->get(route('admin.media.show', $this->ticketFile))
            ->assertNotFound();
    }

    public function test_company_logo_is_not_served_by_the_route(): void
    {
        CompanyLogoService::store(Company::query()->findOrFail($this->admin->company_id), UploadedFile::fake()->image('logo.png'));
        $logo = Media::query()->where('collection_name', 'logo')->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.media.show', $logo))->assertNotFound();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function downloadOnlyTypes(): array
    {
        return [
            'html' => ['notes.html', '<html><script>alert(document.cookie)</script></html>', 'text/html'],
            'svg' => ['diagram.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>', 'image/svg+xml'],
            'plain text' => ['notes.txt', "just text\n", 'text/plain'],
            'pdf' => ['report.pdf', self::PDF, 'application/pdf'],
        ];
    }

    #[DataProvider('downloadOnlyTypes')]
    public function test_types_a_browser_could_run_or_embed_download_instead_of_rendering(string $name, string $contents, string $mime): void
    {
        $media = $this->store($this->ticket, 'attachments', $name, $contents);
        $this->assertSame($mime, $media->mime_type, 'The fixture is the type this case is about.');

        $response = $this->actingAs($this->admin)->get(route('admin.media.show', $media));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_ticket_and_spk_pages_link_media_to_the_authorized_route(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.tickets.show', $this->ticket))
            ->assertInertia(fn (Assert $page) => $page
                ->where('ticket.data.attachments.0.url', '/admin/media/'.$this->ticketFile->public_id)
                ->where('ticket.data.attachments.0.file_path', '/admin/media/'.$this->ticketFile->public_id));

        $this->actingAs($this->admin)
            ->get(route('admin.spk.show', $this->workOrder))
            ->assertInertia(fn (Assert $page) => $page
                ->where('workOrder.data.evidence.0.url', '/admin/media/'.$this->evidenceFile->public_id)
                ->where('workOrder.data.evidence.0.file_path', '/admin/media/'.$this->evidenceFile->public_id));
    }

    public function test_document_list_links_to_the_authorized_route(): void
    {
        $this->actingAs($this->admin);

        $this->assertSame('/admin/media/'.$this->documentFile->public_id, DocumentService::list($this->customer)[0]['url']);
    }

    private function outsider(): User
    {
        $user = User::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($user, 'admin');

        return $user;
    }

    /** @return array{0: User, 1: Customer} */
    private function portalUser(): array
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($user, 'customer');

        return [$user, Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => $user->id,
        ])];
    }

    private function makeTicket(TicketCategory $category, int $customerId): Ticket
    {
        return Ticket::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'TKT-'.now()->year.'-'.fake()->unique()->numberBetween(10000, 99999),
            'title' => 'Media access ticket',
            'description' => 'Who may open the attachment',
            'source' => 'internal',
            'category_id' => $category->id,
            'customer_id' => $customerId,
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'sla_deadline' => now()->addDay(),
        ]);
    }

    private function store(Model&HasMedia $owner, string $collection, string $name, string $bytes): Media
    {
        return $owner->addMediaFromString($bytes)
            ->usingFileName($name)
            ->withCustomProperties(['company_id' => $owner->getAttribute('company_id')])
            ->toMediaCollection($collection);
    }
}
