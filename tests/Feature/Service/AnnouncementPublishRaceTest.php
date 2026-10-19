<?php

namespace Tests\Feature\Service;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Modules\Service\Jobs\SendAnnouncementNotifications;
use Modules\Service\Models\Announcement;
use Modules\Service\Services\AnnouncementService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * Item 16 of integrity-hardening: publish checks the status after it holds the row lock, so two publishes of one
 * draft make one publish, one audit row and one notification dispatch.
 */
class AnnouncementPublishRaceTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    /** Both workers read the draft, wait 300 ms so the other has read it too, then publish. */
    private const PUBLISH = <<<'PHP'
        config(['logging.default' => 'null']);
        Illuminate\Support\Facades\Bus::fake();

        $announcement = Modules\Service\Models\Announcement::query()->findOrFail($args['announcement_id']);
        usleep(300000);

        app(Modules\Service\Services\AnnouncementService::class)->publish($announcement);

        return ['dispatched' => Illuminate\Support\Facades\Bus::dispatched(Modules\Service\Jobs\SendAnnouncementNotifications::class)->count()];
    PHP;

    private function draft(string $kind = 'outage'): Announcement
    {
        $this->createCompanyUser();

        return Announcement::factory()->create([
            'company_id' => CompanyService::currentId(),
            'kind' => $kind,
            'starts_at' => now()->subHour(),
            'ends_at' => null,
        ]);
    }

    private function publishedAudits(Announcement $announcement): int
    {
        return Activity::query()
            ->where('log_name', 'announcement')
            ->where('description', 'published')
            ->where('subject_type', Announcement::class)
            ->where('subject_id', $announcement->id)
            ->count();
    }

    public function test_publishing_one_draft_twice_at_once_publishes_once(): void
    {
        $announcement = $this->draft();
        $companyId = (int) $announcement->company_id;
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $companyId, 'announcement_id' => $announcement->id],
                ['company_id' => $companyId, 'announcement_id' => $announcement->id],
            ], self::PUBLISH);

            $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $losers = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

            $this->assertCount(1, $winners, json_encode($results));
            $this->assertCount(1, $losers, json_encode($results));
            $this->assertSame(['ok' => false, 'status' => 422, 'errors' => ['status' => ['Pengumuman sudah diterbitkan.']]], $losers[0]);
            $this->assertSame(1, $winners[0]['result']['dispatched'], 'the publish that won dispatches the notification once');
            $this->assertSame(1, $this->publishedAudits($announcement), 'one publish, one audit row');
            $this->assertNotNull(Announcement::withoutCompany()->findOrFail($announcement->id)->published_at);
        } finally {
            $this->purgeCompany($companyId);
        }
    }

    public function test_publishing_from_a_copy_that_went_stale_changes_nothing(): void
    {
        Bus::fake();
        $announcement = $this->draft();
        $stale = Announcement::query()->findOrFail($announcement->id);
        $service = app(AnnouncementService::class);

        $service->publish($announcement);

        try {
            $service->publish($stale);
            $this->fail('A published announcement cannot be published again.');
        } catch (ValidationException $e) {
            $this->assertSame(['status' => ['Pengumuman sudah diterbitkan.']], $e->errors());
        }

        Bus::assertDispatchedTimes(SendAnnouncementNotifications::class, 1);
        $this->assertSame(1, $this->publishedAudits($announcement));
    }

    public function test_a_publish_that_waited_for_the_end_of_the_period_is_refused(): void
    {
        Bus::fake();
        $announcement = $this->draft();
        $stale = Announcement::query()->findOrFail($announcement->id);
        Announcement::query()->whereKey($announcement->id)->update(['ends_at' => now()->subMinute()]);

        try {
            app(AnnouncementService::class)->publish($stale);
            $this->fail('An announcement whose period ended cannot be published.');
        } catch (ValidationException $e) {
            $this->assertSame(['ends_at' => ['Periode pengumuman sudah berakhir.']], $e->errors());
        }

        $this->assertNull($announcement->fresh()->published_at);
        Bus::assertNothingDispatched();
    }

    public function test_publish_leaves_the_given_model_current(): void
    {
        Bus::fake();
        $announcement = $this->draft('info');

        $published = app(AnnouncementService::class)->publish($announcement);

        $this->assertNotNull($announcement->published_at);
        $this->assertTrue($announcement->is_active);
        $this->assertTrue($published->is($announcement));
    }
}
