<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyLogoService
{
    /**
     * The new file is stored first; the old one goes only after the commit, so a failure never leaves the company without a logo or with a stray file.
     * Inside a caller's transaction it joins that transaction: Laravel drops the rollback callbacks of a nested transaction that already committed.
     */
    public static function store(Company $company, UploadedFile $logo): void
    {
        $contents = self::webpContents($logo);

        $replace = function () use ($company, $contents): void {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $old = $company->media()->where('collection_name', 'logo')->get();

            $new = $company->addMediaFromString($contents)
                ->withCustomProperties(['company_id' => $company->id])
                ->usingFileName('logo.webp')
                ->toMediaCollection('logo', 'public');

            DB::afterRollBack(fn () => $new->delete());
            DB::afterCommit(fn () => $old->each->delete());
        };

        DB::transactionLevel() === 0 ? DB::transaction($replace) : $replace();
    }

    private static function webpContents(UploadedFile $logo): string
    {
        $image = @imagecreatefromstring($logo->getContent());

        if ($image === false) {
            throw ValidationException::withMessages(['logo' => 'Berkas logo tidak dapat dibaca.']);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, 512 / max($width, 1), 512 / max($height, 1));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagewebp($resized, null, 82);
        $contents = ob_get_clean();

        if ($contents === false || $contents === '') {
            throw ValidationException::withMessages(['logo' => 'Berkas logo tidak dapat dikonversi.']);
        }

        return $contents;
    }
}
