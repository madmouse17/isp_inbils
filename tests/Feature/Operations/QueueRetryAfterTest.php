<?php

namespace Tests\Feature\Operations;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Service\Jobs\SendAnnouncementNotifications;
use ReflectionClass;
use Tests\TestCase;

class QueueRetryAfterTest extends TestCase
{
    public function test_retry_after_outlasts_the_longest_job_timeout(): void
    {
        $checked = 0;

        foreach ($this->queuedJobs() as $class => $source) {
            $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;
            if ($timeout === null) {
                continue;
            }

            // Tests run on the sync queue; production workers serve the database connection unless a job names its own.
            $connection = preg_match('/onConnection\(\'(\w+)\'\)/', $source, $m) ? $m[1] : 'database';
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

            $this->assertGreaterThan($timeout, $retryAfter, "{$class} timeout {$timeout}s must stay below retry_after {$retryAfter}s of connection {$connection}");
            $checked++;
        }

        $this->assertGreaterThanOrEqual(5, $checked, 'Job scan found too few jobs with a $timeout');
    }

    public function test_the_announcement_lock_expires(): void
    {
        $this->assertGreaterThan(0, (new ReflectionClass(SendAnnouncementNotifications::class))->getDefaultProperties()['uniqueFor'] ?? 0);
    }

    /** @return array<class-string, string> job class => source code */
    private function queuedJobs(): array
    {
        $files = array_merge(
            glob(app_path('Jobs/{,*/}*.php'), GLOB_BRACE) ?: [],
            glob(base_path('Modules/*/app/Jobs/{,*/}*.php'), GLOB_BRACE) ?: [],
        );

        $jobs = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (! preg_match('/^namespace (.+);/m', $source, $ns) || ! preg_match('/^(?:final |abstract )?class (\w+)/m', $source, $cls)) {
                continue;
            }
            $class = $ns[1].'\\'.$cls[1];
            if (class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
                $jobs[$class] = $source;
            }
        }

        return $jobs;
    }
}
