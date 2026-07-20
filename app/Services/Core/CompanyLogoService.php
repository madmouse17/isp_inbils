<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CompanyLogoService
{
    public static function store(Company $company, UploadedFile $logo): void
    {
        $company->clearMediaCollection('logo');

        if ($logo->extension() === 'svg') {
            $company->addMedia($logo)
                ->withCustomProperties(['company_id' => $company->id])
                ->usingFileName('logo.svg')
                ->toMediaCollection('logo', 'public');

            return;
        }

        $company->addMediaFromString(self::webpContents($logo))
            ->withCustomProperties(['company_id' => $company->id])
            ->usingFileName('logo.webp')
            ->toMediaCollection('logo', 'public');
    }

    private static function webpContents(UploadedFile $logo): string
    {
        $image = imagecreatefromstring($logo->getContent());

        if ($image === false) {
            throw ValidationException::withMessages(['logo' => 'Logo file could not be processed.']);
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

        imagedestroy($image);
        imagedestroy($resized);

        if ($contents === false || $contents === '') {
            throw ValidationException::withMessages(['logo' => 'Logo file could not be converted.']);
        }

        return $contents;
    }
}
