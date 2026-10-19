<?php

namespace Tests\Feature\Integrity;

use App\Models\Core\Company;
use App\Services\Core\CompanyLogoService;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Service\Models\PromoBanner;
use Modules\Service\Services\PromoBannerService;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * Item 19 of integrity-hardening: replacing a logo or a banner image stores the new file first and removes the old one
 * only after the commit, so a failed or rolled-back replace never leaves the owner without an image, with two images,
 * or with a file no row points to.
 */
class MediaReplaceTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->createCompanyUser();
    }

    private function company(): Company
    {
        return Company::query()->findOrFail(CompanyService::currentId());
    }

    private function bannerData(?UploadedFile $image): array
    {
        return [
            'title' => 'Promo',
            'alt_text' => 'Promo banner',
            'link_kind' => 'none',
            'audience' => 'all',
            'starts_at' => '2026-10-18 08:00',
            'image' => $image,
        ];
    }

    private function exists(Media $media): bool
    {
        return Storage::disk($media->disk)->exists($media->getPathRelativeToRoot());
    }

    /** @return list<string> every file the disk holds, to spot an orphan. */
    private function files(string $disk): array
    {
        return Storage::disk($disk)->allFiles();
    }

    private function bannerWithImage(): array
    {
        $banner = app(PromoBannerService::class)->create($this->bannerData(UploadedFile::fake()->image('first.png')));

        return [$banner, $banner->getFirstMedia('promo_banner')];
    }

    public function test_a_logo_that_cannot_be_converted_keeps_the_current_logo(): void
    {
        $company = $this->company();
        CompanyLogoService::store($company, UploadedFile::fake()->image('logo.png', 300, 300));
        $current = $company->fresh()->getFirstMedia('logo');

        try {
            CompanyLogoService::store($company, UploadedFile::fake()->createWithContent('logo.png', 'not an image'));
            $this->fail('An unreadable file cannot become the logo.');
        } catch (ValidationException) {
            // expected
        }

        $kept = $company->fresh()->getMedia('logo');
        $this->assertCount(1, $kept);
        $this->assertSame($current->id, $kept->first()->id);
        $this->assertTrue($this->exists($current));
    }

    public function test_a_rolled_back_logo_or_banner_replace_keeps_the_old_file_and_drops_the_new_one(): void
    {
        $company = $this->company();
        CompanyLogoService::store($company, UploadedFile::fake()->image('logo.png', 300, 300));
        $oldLogo = $company->fresh()->getFirstMedia('logo');
        [$banner, $oldBanner] = $this->bannerWithImage();
        $publicBefore = $this->files('public');
        $localBefore = $this->files('local');

        try {
            DB::transaction(function () use ($company): void {
                CompanyLogoService::store($company, UploadedFile::fake()->image('next.png', 400, 400));

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // expected
        }

        // The audit row is the last write of the banner update: failing it rolls the update back after the image was stored.
        Event::listen('eloquent.creating: '.config('activitylog.activity_model'), fn () => throw new RuntimeException('audit failed'));

        try {
            app(PromoBannerService::class)->update($banner, $this->bannerData(UploadedFile::fake()->image('next.png')));
            $this->fail('The audit failure must abort the update.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit failed', $e->getMessage());
        }

        $this->assertSame($oldLogo->id, $company->fresh()->getFirstMedia('logo')->id);
        $this->assertSame($oldBanner->id, $banner->fresh()->getFirstMedia('promo_banner')->id);
        $this->assertTrue($this->exists($oldLogo));
        $this->assertTrue($this->exists($oldBanner));
        $this->assertEqualsCanonicalizing($publicBefore, $this->files('public'), 'the rolled-back logo left a file behind');
        $this->assertEqualsCanonicalizing($localBefore, $this->files('local'), 'the rolled-back banner image left a file behind');
    }

    public function test_the_old_file_goes_only_after_commit(): void
    {
        $company = $this->company();
        CompanyLogoService::store($company, UploadedFile::fake()->image('logo.png', 300, 300));
        $oldLogo = $company->fresh()->getFirstMedia('logo');
        [$banner, $oldBanner] = $this->bannerWithImage();

        DB::transaction(function () use ($company, $banner, $oldLogo, $oldBanner): void {
            CompanyLogoService::store($company, UploadedFile::fake()->image('next.png', 400, 400));
            app(PromoBannerService::class)->update($banner, $this->bannerData(UploadedFile::fake()->image('next.png')));

            $this->assertTrue($this->exists($oldLogo), 'the old logo was removed before the commit');
            $this->assertTrue($this->exists($oldBanner), 'the old banner image was removed before the commit');
        });

        $this->assertFalse($this->exists($oldLogo));
        $this->assertFalse($this->exists($oldBanner));
        $this->assertSame(1, $company->fresh()->getMedia('logo')->count());
        $this->assertSame(1, $banner->fresh()->getMedia('promo_banner')->count());
        $this->assertNotSame($oldLogo->id, $company->fresh()->getFirstMedia('logo')->id);
        $this->assertNotSame($oldBanner->id, $banner->fresh()->getFirstMedia('promo_banner')->id);
    }

    public function test_a_committed_replace_leaves_exactly_one_file_each(): void
    {
        $company = $this->company();
        CompanyLogoService::store($company, UploadedFile::fake()->image('logo.png', 300, 300));
        CompanyLogoService::store($company, UploadedFile::fake()->image('logo.png', 500, 500));
        [$banner] = $this->bannerWithImage();
        app(PromoBannerService::class)->update($banner, $this->bannerData(UploadedFile::fake()->image('second.png')));

        $this->assertCount(1, $this->files('public'));
        $this->assertCount(1, $this->files('local'));
        $this->assertSame(1, PromoBanner::query()->findOrFail($banner->id)->getMedia('promo_banner')->count());
    }

    public function test_an_update_without_a_new_image_keeps_the_banner_image(): void
    {
        [$banner, $media] = $this->bannerWithImage();

        app(PromoBannerService::class)->update($banner, $this->bannerData(null));

        $this->assertSame($media->id, $banner->fresh()->getFirstMedia('promo_banner')->id);
        $this->assertTrue($this->exists($media));
    }
}
