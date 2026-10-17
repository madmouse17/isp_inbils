<?php

namespace Tests\Unit\Platform;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The platform worker is configured in five places that must agree: config/queue.php, compose.yaml, the systemd and
 * supervisor examples, and the ops doc. A timeout that grows in one of them and not the others is how an hour-long job
 * ends up killed or run twice.
 */
class PlatformQueueArtifactsTest extends TestCase
{
    private const WORKER = 'queue:work platform --queue=platform';

    public function test_the_compose_service_runs_the_platform_worker_and_waits_for_a_running_job_on_stop(): void
    {
        $services = Yaml::parseFile(base_path('compose.yaml'))['services'];

        $platform = $services['platform-queue'];
        $this->assertSame(
            ['php', 'artisan', 'queue:work', 'platform', '--queue=platform', '--sleep=3', '--tries=1', '--timeout=3600', '--no-interaction'],
            $platform['command'],
        );
        $this->assertSame('3660s', $platform['stop_grace_period']);
        $this->assertSame($services['queue']['image'], $platform['image'], 'the same image, so the same code, as the default worker');
        $this->assertSame($services['queue']['env_file'], $platform['env_file']);
        $this->assertArrayHasKey('healthcheck', $platform);

        $this->assertStringContainsString('--queue=default,whatsapp', implode(' ', $services['queue']['command']));
        $this->assertStringNotContainsString('platform', implode(' ', $services['queue']['command']), 'the default worker never reads the platform queue');
    }

    public function test_the_worker_timeouts_sit_below_the_connections_retry_after_everywhere(): void
    {
        $retryAfter = config('queue.connections.platform.retry_after');
        $this->assertSame(3660, $retryAfter);

        $systemd = $this->file('deploy/systemd/platform-queue-worker.service.example');
        $this->assertStringContainsString(self::WORKER.' --sleep=3 --tries=1 --timeout=3600', $systemd);
        $this->assertMatchesRegularExpression('/^TimeoutStopSec=(\d+)$/m', $systemd);
        preg_match('/^TimeoutStopSec=(\d+)$/m', $systemd, $stop);
        $this->assertGreaterThanOrEqual(3660, (int) $stop[1]);

        $supervisor = $this->file('docs/operations/examples/supervisor-laravel.conf.example');
        $this->assertStringContainsString('[program:inbils-platform-queue]', $supervisor);
        $this->assertStringContainsString(self::WORKER.' --sleep=3 --tries=1 --timeout=3600', $supervisor);
        preg_match('/\[program:inbils-platform-queue\].*?stopwaitsecs=(\d+)/s', $supervisor, $wait);
        $this->assertGreaterThanOrEqual(3660, (int) $wait[1]);

        $plain = $this->file('deploy/systemd/queue-worker.service.example');
        $this->assertStringNotContainsString('platform', $plain, 'the default worker unit is unchanged');
    }

    public function test_the_env_examples_and_the_ops_docs_name_the_worker_command(): void
    {
        foreach (['.env.example', '.env.docker.example'] as $file) {
            $this->assertStringContainsString('platform', $this->file($file), "{$file} must tell the operator about the platform worker");
            $this->assertStringContainsString('docs/operations/platform-queue.md', $this->file($file));
        }

        $doc = $this->file('docs/operations/platform-queue.md');

        foreach ([self::WORKER.' --sleep=3 --tries=1 --timeout=3600', 'retry_after', '3660', 'TimeoutStopSec=3660', 'stopwaitsecs=3700', 'stop_grace_period: 3660s', 'SeedTenantDemoDataJob', 'queue:restart', 'platform-queue'] as $needle) {
            $this->assertStringContainsString($needle, $doc);
        }

        foreach (['DEPLOYMENT.md', 'SUPERVISION.md', 'DOCKER.md'] as $file) {
            $this->assertStringContainsString('platform-queue.md', $this->file("docs/operations/{$file}"), "{$file} points to the platform queue doc");
        }

        $purge = $this->file('docs/operations/tenant-purge.md');
        $this->assertStringContainsString('php artisan inbils:platform:purge-company {code}', $purge);
        $this->assertStringContainsString('30 days', $purge);
    }

    private function file(string $relative): string
    {
        $body = file_get_contents(base_path($relative));
        $this->assertIsString($body, "{$relative} must exist");

        return $body;
    }
}
