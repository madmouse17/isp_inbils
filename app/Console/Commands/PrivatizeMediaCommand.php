<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class PrivatizeMediaCommand extends Command
{
    /** Where uploads used to be written. The logo is still written there and stays. */
    private const SOURCE = 'public';

    protected $signature = 'inbils:media:privatize
        {--dry-run : Report what would move without touching files or rows}';

    protected $description = 'Move uploaded media (everything except the company logo) from the public disk to the private disk. Idempotent.';

    public function handle(): int
    {
        $target = (string) config('media-library.disk_name');
        $dryRun = (bool) $this->option('dry-run');

        if (($problem = $this->targetProblem($target, $dryRun)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $moved = $missing = $failed = 0;

        Media::query()
            ->where('disk', self::SOURCE)
            ->where('collection_name', '!=', 'logo')
            ->lazyById(200)
            ->each(function (Media $media) use ($target, $dryRun, &$moved, &$missing, &$failed): void {
                try {
                    if ($this->move($media, $target, $dryRun)) {
                        $moved++;

                        return;
                    }

                    $missing++;
                    $this->warn("Missing {$media->getKey()}: ".self::SOURCE.":{$media->getPathRelativeToRoot()}");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("Media {$media->getKey()}: {$e->getMessage()}");
                }
            });

        $this->info(($dryRun ? 'Would move ' : 'Moved ')."{$moved} media to the private disk ({$target}).");

        if ($missing > 0) {
            $this->error("{$missing} media have no file at the expected path and stay on the public disk; find the files or drop the rows, then rerun.");
        }

        if ($failed > 0) {
            $this->error("Failed {$failed} media; their rows still point at the public disk. Fix the cause and rerun.");
        }

        return $missing + $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Why the run must not start: it would expose files, or delete the only copy of them. */
    private function targetProblem(string $target, bool $dryRun): ?string
    {
        $config = config("filesystems.disks.{$target}");

        if (! is_array($config)) {
            return "Disk '{$target}' (media-library.disk_name) is not configured in config/filesystems.php.";
        }

        if ($target === self::SOURCE || ($config['visibility'] ?? 'private') === 'public') {
            return "Disk '{$target}' (media-library.disk_name) is public; uploads belong on a private disk. Check MEDIA_DISK.";
        }

        // Real runs only, the probe is a write. A target that is the same storage as the source
        // would "copy" every file onto itself and then delete it.
        if (! $dryRun && $this->sameStorage(Storage::disk(self::SOURCE), Storage::disk($target))) {
            return "Disk '{$target}' keeps its files where the public disk does; moving would delete the only copy.";
        }

        return null;
    }

    private function sameStorage(Filesystem $a, Filesystem $b): bool
    {
        $probe = '.privatize-probe-'.Str::uuid()->toString();
        $b->put($probe, '');

        try {
            return $a->exists($probe);
        } finally {
            $b->delete($probe);
        }
    }

    /**
     * The row flips only after the file is verified on the target and gone from the source, so a
     * failure at any step leaves the media readable and a rerun finishes the job.
     *
     * @return bool false when there is no file on either disk
     */
    private function move(Media $media, string $target, bool $dryRun): bool
    {
        $from = Storage::disk(self::SOURCE);
        $to = Storage::disk($target);

        // Owners are tenant-scoped and artisan has no company; the path generator needs the real one.
        $media->setRelation('model', $media->model()->withoutGlobalScopes()->first());

        $original = $media->getPathRelativeToRoot();

        if (! $from->exists($original) && ! $to->exists($original)) {
            return false;
        }

        $directory = dirname($original);

        // CompanyMediaPathGenerator gives every media a directory named after its id. Anything else
        // may hold other media, and the source directory is deleted below.
        if (basename($directory) !== (string) $media->getKey()) {
            throw new RuntimeException("{$directory} is not a directory of its own; refusing to move it.");
        }

        if ($dryRun) {
            $this->line(self::SOURCE." -> {$target}: {$original}");

            return true;
        }

        foreach ($from->allFiles($directory) as $file) {
            $this->copy($from, $to, $file);
        }

        $from->deleteDirectory($directory);

        if ($from->exists($original)) {
            throw new RuntimeException("Could not remove {$original} from the public disk.");
        }

        Media::query()->whereKey($media->getKey())->update(['disk' => $target, 'conversions_disk' => $target]);

        return true;
    }

    private function copy(Filesystem $from, Filesystem $to, string $file): void
    {
        $size = $from->size($file);

        if (! $to->exists($file) || $to->size($file) !== $size) {
            $stream = $from->readStream($file);

            if (! is_resource($stream) || ! $to->writeStream($file, $stream)) {
                throw new RuntimeException("Could not copy {$file}.");
            }

            fclose($stream);
        }

        if ($to->size($file) !== $size) {
            throw new RuntimeException("Copy of {$file} does not match the source.");
        }
    }
}
