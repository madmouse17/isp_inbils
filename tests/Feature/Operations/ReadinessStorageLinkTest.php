<?php

namespace Tests\Feature\Operations;

use App\Support\Observability\ReadinessChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F1-D01: an uploaded logo showed as a broken image because `public/storage` was a real directory on a dev machine, so
 * /storage/... never reached the public disk. `/ready` now says whether the link the app declares in
 * config/filesystems.php is a link to the public root. PHPUnit never serves a file, so inside a test run the check
 * reports `unknown` unless the test puts the app in another environment.
 */
class ReadinessStorageLinkTest extends TestCase
{
    private string $dir;

    private string $link;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/inbils-storage-link-'.Str::random(10);
        $this->root = $this->dir.'/storage/app/public';
        $this->link = $this->dir.'/public/storage';
        mkdir($this->root, 0777, true);
        mkdir($this->dir.'/public', 0777, true);

        config(['filesystems.links' => [$this->link => $this->root]]);
        Cache::put(ReadinessChecker::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), 120);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_a_link_to_the_public_root_is_ok(): void
    {
        $this->outsideTests();
        symlink($this->root, $this->link);

        $this->getJson('/ready')
            ->assertOk()
            ->assertJsonPath('checks.storage_link', 'ok')
            ->assertJsonPath('status', 'ready');
    }

    public function test_a_missing_link_fails_and_degrades_readiness(): void
    {
        $this->outsideTests();

        $this->getJson('/ready')
            ->assertOk()
            ->assertJsonPath('checks.storage_link', 'fail')
            ->assertJsonPath('status', 'degraded');
    }

    public function test_a_real_directory_where_the_link_belongs_fails(): void
    {
        $this->outsideTests();
        mkdir($this->link);
        file_put_contents($this->link.'/stale.txt', 'copied once, never updated');

        $this->getJson('/ready')
            ->assertJsonPath('checks.storage_link', 'fail')
            ->assertJsonPath('status', 'degraded');
    }

    public function test_a_link_to_another_directory_fails(): void
    {
        $this->outsideTests();
        mkdir($this->dir.'/elsewhere');
        symlink($this->dir.'/elsewhere', $this->link);

        $this->getJson('/ready')->assertJsonPath('checks.storage_link', 'fail');
    }

    public function test_a_link_whose_target_is_gone_fails(): void
    {
        $this->outsideTests();
        symlink($this->root, $this->link);
        File::deleteDirectory($this->root);

        $this->getJson('/ready')->assertJsonPath('checks.storage_link', 'fail');
    }

    public function test_the_check_reports_unknown_inside_a_phpunit_run_whatever_the_machine_has(): void
    {
        $this->assertTrue(app()->environment('testing'));

        $this->getJson('/ready')
            ->assertOk()
            ->assertJsonPath('checks.storage_link', 'unknown')
            ->assertJsonPath('status', 'ready');
    }

    public function test_the_answer_stays_public_safe(): void
    {
        $this->outsideTests();

        $body = (string) $this->getJson('/ready')->getContent();

        $this->assertStringNotContainsString($this->dir, $body, 'no path leaves the server');
        $this->assertSame(['status', 'checks'], array_keys(json_decode($body, true)));
    }

    private function outsideTests(): void
    {
        $this->app['env'] = 'local';
    }
}
