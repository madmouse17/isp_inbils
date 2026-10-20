<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\User;
use App\Notifications\Platform\TenantAdminInvitation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * An invitation mail that fails for good leaves a platform row, so the company page can say "Gagal terkirim" instead
 * of showing nothing (F1-D05). The row names the person and the kind of error, never the error text.
 */
class TenantAdminInvitationFailedTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->buildTenant('ALP', 'Alpha Net');
    }

    /** @return Collection<int, Activity> */
    private function rows(): Collection
    {
        return Activity::query()->where('description', 'user.invitation_failed')->get();
    }

    public function test_failed_mail_writes_platform_row(): void
    {
        $admin = $this->alpha['admin'];

        (new TenantAdminInvitation($this->alpha['root']->id, $admin->id))->failed(new RuntimeException('535 login refused by smtp.secret-host.test'));

        $row = $this->rows()->sole();
        $this->assertSame('platform', $row->log_name);
        $this->assertSame([User::class, $admin->id], [$row->subject_type, (int) $row->subject_id]);
        $this->assertSame($this->alpha['root']->id, (int) $row->company_id, 'the row is about the company of the person');
        $this->assertEquals(['user_id' => $admin->id, 'error' => 'RuntimeException'], $row->properties->all());
        $this->assertStringNotContainsString('secret-host', (string) json_encode($row->properties), 'a mail error can name the server');
        $this->assertSame(0, Activity::query()->where('log_name', 'support')->count(), 'only the operator reads it');
    }

    public function test_the_queue_hands_the_failure_to_the_notification(): void
    {
        $admin = $this->alpha['admin'];
        $job = new SendQueuedNotifications($admin, new TenantAdminInvitation($this->alpha['root']->id, $admin->id), ['mail']);

        $job->failed(new RuntimeException('boom'));

        $this->assertSame($admin->id, $this->rows()->sole()->properties['user_id']);
    }

    public function test_a_send_that_fails_for_good_reaches_the_hook(): void
    {
        $admin = $this->alpha['admin'];

        try {
            // The company of the mail is gone: building the mail throws, and the sync queue fails the job as a worker would.
            $admin->notify(new TenantAdminInvitation(999999, $admin->id));
            $this->fail('The send should have failed.');
        } catch (ModelNotFoundException) {
            // expected
        }

        $row = $this->rows()->sole();
        $this->assertEquals(['user_id' => $admin->id, 'error' => 'ModelNotFoundException'], $row->properties->all());
    }

    public function test_job_queued_before_deploy_fails_quietly(): void
    {
        // A job serialised before the user id existed holds the company id alone.
        $class = TenantAdminInvitation::class;
        $old = unserialize(sprintf('O:%d:"%s":1:{s:9:"companyId";i:%d;}', strlen($class), $class, $this->alpha['root']->id));

        $this->assertInstanceOf(TenantAdminInvitation::class, $old);
        $old->failed(new RuntimeException('boom'));
        (new TenantAdminInvitation($this->alpha['root']->id))->failed(new RuntimeException('boom'));

        $this->assertCount(0, $this->rows(), 'no person to write it about');
    }

    public function test_a_person_who_is_gone_leaves_no_row(): void
    {
        (new TenantAdminInvitation($this->alpha['root']->id, 987654))->failed(new RuntimeException('boom'));

        $this->assertCount(0, $this->rows());
    }
}
