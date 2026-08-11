<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait UploadsMedia
{
    /** @param array<string, mixed> $customProperties */
    protected function storeMedia(HasMedia $model, UploadedFile $file, string $collection, array $customProperties = []): Media
    {
        return $model->addMedia($file)
            ->withCustomProperties($customProperties)
            ->toMediaCollection($collection, 'public');
    }
}
