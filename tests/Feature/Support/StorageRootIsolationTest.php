<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\ParallelTesting;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F0-D03: PHPUnit, the E2E server and the dev site wrote into one tree, so a company id reused across their databases
 * mixed their files. One key, STORAGE_ENV_SUFFIX, read only in config/filesystems.php, gives each environment its own
 * root under the same parents. A test addresses a file as `companies/{id}/...` relative to its disk, so none of them
 * changes; dev and production keep the empty suffix and their current roots.
 */
class StorageRootIsolationTest extends TestCase
{
    public function test_testing_disks_live_under_testing_suffix(): void
    {
        // phpunit.xml pins /testing. A parallel worker's tests get /testing_<token> (TestCase::createApplication): its database
        // hands out the same company and media ids again, so two workers on one tree would overwrite each other's files.
        $token = ParallelTesting::token();
        $suffix = '/testing'.($token === false ? '' : "_{$token}");

        $this->assertSame($suffix, env('STORAGE_ENV_SUFFIX'), 'phpunit.xml pins the suffix, a parallel worker adds its token');
        $this->assertSame(storage_path('app/private'.$suffix), config('filesystems.disks.local.root'));
        $this->assertSame(storage_path('app/public'.$suffix), config('filesystems.disks.public.root'));
        $this->assertStringEndsWith('/storage'.$suffix, (string) config('filesystems.disks.public.url'));
    }

    public function test_the_public_link_still_points_at_the_base_public_root(): void
    {
        // /storage/testing/... is served from storage/app/public/testing, which sits under the one linked directory.
        $this->assertSame([public_path('storage') => storage_path('app/public')], config('filesystems.links'));
    }

    public function test_an_empty_suffix_keeps_the_roots_of_the_dev_and_production_install(): void
    {
        $disks = $this->filesystemsWith('')['disks'];

        $this->assertSame(storage_path('app/private'), $disks['local']['root']);
        $this->assertSame(storage_path('app/public'), $disks['public']['root']);
        $this->assertSame(rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage', $disks['public']['url']);
    }

    public function test_the_e2e_suffix_moves_both_roots_and_the_public_url_together(): void
    {
        $disks = $this->filesystemsWith('/e2e')['disks'];

        $this->assertSame(storage_path('app/private/e2e'), $disks['local']['root']);
        $this->assertSame(storage_path('app/public/e2e'), $disks['public']['root']);
        $this->assertStringEndsWith('/storage/e2e', $disks['public']['url']);
    }

    /** @return array<string, array{string}> */
    public static function badSuffixes(): array
    {
        return [
            'no leading slash' => ['e2e'],
            'only a slash' => ['/'],
            'a way out of the tree' => ['/../x'],
            'two levels' => ['/a/b'],
            'a space' => ['/e 2e'],
            'a trailing slash' => ['/e2e/'],
        ];
    }

    #[DataProvider('badSuffixes')]
    public function test_a_suffix_is_one_plain_directory_name(string $suffix): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('STORAGE_ENV_SUFFIX');

        $this->filesystemsWith($suffix);
    }

    /**
     * The config file as the framework reads it under another suffix. env() reads $_ENV and $_SERVER first, which is
     * also where phpunit.xml puts the pinned value, so both carry the one under test and are put back afterwards.
     *
     * @return array<string, mixed>
     */
    private function filesystemsWith(string $suffix): array
    {
        $env = $_ENV['STORAGE_ENV_SUFFIX'] ?? null;
        $server = $_SERVER['STORAGE_ENV_SUFFIX'] ?? null;
        $process = getenv('STORAGE_ENV_SUFFIX');
        $_ENV['STORAGE_ENV_SUFFIX'] = $_SERVER['STORAGE_ENV_SUFFIX'] = $suffix;
        putenv("STORAGE_ENV_SUFFIX={$suffix}");

        try {
            return require config_path('filesystems.php');
        } finally {
            if ($env === null) {
                unset($_ENV['STORAGE_ENV_SUFFIX']);
            } else {
                $_ENV['STORAGE_ENV_SUFFIX'] = $env;
            }

            if ($server === null) {
                unset($_SERVER['STORAGE_ENV_SUFFIX']);
            } else {
                $_SERVER['STORAGE_ENV_SUFFIX'] = $server;
            }

            putenv($process === false ? 'STORAGE_ENV_SUFFIX' : "STORAGE_ENV_SUFFIX={$process}");
        }
    }
}
