<?php

namespace App\Support\Media;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class CompanyMediaPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->basePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->basePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->basePath($media).'/responsive-images/';
    }

    private function basePath(Media $media): string
    {
        $model = $media->model;
        $companyId = $media->getCustomProperty('company_id') ?? $this->companyId($model);

        if ($model instanceof Company && $media->collection_name === 'logo') {
            return implode('/', [
                'companies',
                $companyId ?: 'system',
                'company-logo',
                $media->getKey(),
            ]);
        }

        return implode('/', [
            'companies',
            $companyId ?: 'system',
            str(class_basename($media->model_type))->snake()->plural()->toString(),
            $media->model_id,
            $media->collection_name,
            $media->getKey(),
        ]);
    }

    private function companyId(?Model $model): int|string|null
    {
        if ($model instanceof Company) {
            return $model->getKey();
        }

        return $model?->getAttribute('company_id');
    }
}
