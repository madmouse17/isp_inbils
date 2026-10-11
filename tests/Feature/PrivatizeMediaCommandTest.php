<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Services\Core\CompanyLogoService;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class PrivatizeMediaCommandTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private int $companyId;

    private Ticket $ticket;

    private Customer $customer;

    private Media $attachment;

    private Media $document;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $admin = $this->createCompanyUser();
        $this->companyId = (int) $admin->company_id;
        $category = TicketCategory::query()->create([
            'company_id' => $this->companyId,
            'name' => 'Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
        $this->ticket = Ticket::query()->create([
            'company_id' => $this->companyId,
            'code' => 'TKT-'.now()->year.'-'.fake()->unique()->numberBetween(10000, 99999),
            'title' => 'Legacy attachment',
            'source' => 'internal',
            'category_id' => $category->id,
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $admin->id,
            'sla_deadline' => now()->addDay(),
        ]);
        $this->customer = Customer::factory()->create(['company_id' => $this->companyId]);

        // What every upload looked like before the private disk: explicit `public`.
        $this->attachment = $this->legacy($this->ticket, 'attachments', 'site.png');
        $this->document = $this->legacy($this->customer, 'documents', 'contract.pdf');
        CompanyLogoService::store(Company::query()->findOrFail($this->companyId), UploadedFile::fake()->image('logo.png'));

        // Artisan has no signed-in user and no company: every tenant scope fails closed.
        CompanyService::resetCache();
    }

    public function test_dry_run_reports_and_moves_nothing(): void
    {
        $this->artisan('inbils:media:privatize', ['--dry-run' => true])
            ->expectsOutputToContain('Would move 2 media')
            ->assertExitCode(0);

        foreach ([$this->attachment, $this->document] as $media) {
            $this->assertSame('public', $media->fresh()->disk);
            Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
            Storage::disk('local')->assertMissing($media->getPathRelativeToRoot());
        }
        $this->assertSame([], Storage::disk('local')->allFiles(), 'A dry run writes nothing, not even a probe file.');
    }

    public function test_it_moves_files_and_rows_to_the_private_disk_but_not_the_logo(): void
    {
        $paths = [$this->attachment->getPathRelativeToRoot(), $this->document->getPathRelativeToRoot()];
        $contents = Storage::disk('public')->get($paths[0]);
        $logo = Media::query()->where('collection_name', 'logo')->firstOrFail();

        $this->artisan('inbils:media:privatize')
            ->expectsOutputToContain('Moved 2 media')
            ->assertExitCode(0);

        foreach ([$this->attachment, $this->document] as $media) {
            $row = $media->fresh();
            $this->assertSame('local', $row->disk);
            $this->assertSame('local', $row->conversions_disk);
            Storage::disk('local')->assertExists($row->getPathRelativeToRoot());
            Storage::disk('public')->assertMissing($row->getPathRelativeToRoot());
        }
        $this->assertSame($contents, Storage::disk('local')->get($paths[0]));

        $this->assertSame('public', $logo->fresh()->disk);
        Storage::disk('public')->assertExists($logo->getPathRelativeToRoot());
    }

    public function test_a_second_run_moves_nothing(): void
    {
        $this->artisan('inbils:media:privatize')->assertExitCode(0);

        $this->artisan('inbils:media:privatize')
            ->expectsOutputToContain('Moved 0 media')
            ->assertExitCode(0);
    }

    public function test_the_old_storage_url_no_longer_serves_a_moved_file(): void
    {
        $path = $this->attachment->getPathRelativeToRoot();

        $this->artisan('inbils:media:privatize')->assertExitCode(0);

        $this->assertContains($this->get('/storage/'.$path)->getStatusCode(), [403, 404]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_media_of_a_soft_deleted_owner_is_moved_too(): void
    {
        CompanyService::useCompanyForTests($this->companyId);
        $this->ticket->delete();
        CompanyService::resetCache();

        $this->artisan('inbils:media:privatize')
            ->expectsOutputToContain('Moved 2 media')
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($this->attachment->getPathRelativeToRoot());
        Storage::disk('public')->assertMissing($this->attachment->getPathRelativeToRoot());
    }

    public function test_a_row_whose_file_is_gone_is_listed_and_fails_the_run(): void
    {
        $path = $this->attachment->getPathRelativeToRoot();
        Storage::disk('public')->delete($path);

        $this->artisan('inbils:media:privatize')
            ->expectsOutputToContain('Moved 1 media')
            ->expectsOutputToContain("Missing {$this->attachment->id}: public:{$path}")
            ->expectsOutputToContain('1 media have no file at the expected path')
            ->assertExitCode(1);

        $this->assertSame('public', $this->attachment->fresh()->disk);
    }

    public function test_a_run_that_died_after_the_copy_finishes_the_job(): void
    {
        $path = $this->attachment->getPathRelativeToRoot();
        Storage::disk('local')->put($path, Storage::disk('public')->get($path));
        Storage::disk('public')->delete($path);

        $this->artisan('inbils:media:privatize')
            ->expectsOutputToContain('Moved 2 media')
            ->assertExitCode(0);

        $this->assertSame('local', $this->attachment->fresh()->disk);
        Storage::disk('local')->assertExists($path);
    }

    public function test_one_failing_media_does_not_stop_the_others_and_fails_the_run(): void
    {
        // An owner class that no longer exists: the path of this media cannot be worked out.
        Media::query()->whereKey($this->document->getKey())->update(['model_type' => 'App\\Models\\Gone']);

        $this->artisan('inbils:media:privatize')
            ->expectsOutputToContain('Moved 1 media')
            ->expectsOutputToContain('Failed 1 media')
            ->assertExitCode(1);

        $this->assertSame('local', $this->attachment->fresh()->disk);
        $this->assertSame('public', $this->document->fresh()->disk);
    }

    public function test_only_media_on_the_public_disk_is_touched(): void
    {
        Media::query()->whereKey($this->document->getKey())->update(['disk' => 's3']);

        $this->artisan('inbils:media:privatize')
            ->expectsOutputToContain('Moved 1 media')
            ->assertExitCode(0);

        $this->assertSame('s3', $this->document->fresh()->disk);
        Storage::disk('public')->assertExists($this->document->getPathRelativeToRoot());
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>|null}> */
    public static function unsafeTargets(): array
    {
        return [
            'the public disk itself' => ['public', null],
            'a disk with public visibility' => ['cdn', ['driver' => 'local', 'root' => '/nonexistent/inbils-cdn', 'visibility' => 'public']],
            'a disk that is not configured' => ['vault', null],
        ];
    }

    /** @param  array<string, mixed>|null  $config */
    #[DataProvider('unsafeTargets')]
    public function test_it_refuses_a_target_that_is_not_a_private_disk(string $disk, ?array $config): void
    {
        config(['media-library.disk_name' => $disk]);

        if ($config !== null) {
            config(["filesystems.disks.{$disk}" => $config]);
        }

        $this->artisan('inbils:media:privatize')
            ->expectsOutputToContain("Disk '{$disk}'")
            ->assertExitCode(1);

        foreach ([$this->attachment, $this->document] as $media) {
            $this->assertSame('public', $media->fresh()->disk);
            Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        }
    }

    public function test_it_refuses_a_private_disk_that_shares_its_storage_with_the_public_one(): void
    {
        config([
            'filesystems.disks.alias' => ['driver' => 'local', 'root' => Storage::disk('public')->path('')],
            'media-library.disk_name' => 'alias',
        ]);

        $this->artisan('inbils:media:privatize')
            ->expectsOutputToContain('delete the only copy')
            ->assertExitCode(1);

        foreach ([$this->attachment, $this->document] as $media) {
            $this->assertSame('public', $media->fresh()->disk);
            Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        }
        $this->assertSame([], array_values(array_filter(
            Storage::disk('public')->allFiles(),
            fn (string $file): bool => str_contains($file, 'privatize-probe'),
        )), 'The probe file is removed again.');
    }

    public function test_it_never_moves_or_deletes_a_directory_that_is_not_the_medias_own(): void
    {
        $nested = dirname($this->attachment->getPathRelativeToRoot()).'/sub/nested.png';
        Storage::disk('public')->put($nested, 'x');
        Media::query()->whereKey($this->attachment->getKey())->update(['file_name' => 'sub/nested.png']);

        $this->artisan('inbils:media:privatize')
            ->expectsOutputToContain('Moved 1 media')
            ->expectsOutputToContain('Failed 1 media')
            ->assertExitCode(1);

        Storage::disk('public')->assertExists($nested);
        $this->assertSame('public', $this->attachment->fresh()->disk);
    }

    private function legacy(Ticket|Customer $owner, string $collection, string $name): Media
    {
        $bytes = str_ends_with($name, '.pdf')
            ? "%PDF-1.4\n%%EOF\n"
            : base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        return $owner->addMediaFromString($bytes)
            ->usingFileName($name)
            ->withCustomProperties(['company_id' => $this->companyId])
            ->toMediaCollection($collection, 'public');
    }
}
