<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Core\CompanyService;
use App\Support\RecordNotFound;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    /**
     * Types a browser renders in place. Everything else downloads, so an uploaded HTML or SVG file
     * can never run scripts on the application's origin. PDF is not listed: the only header that
     * contains a PDF viewer, a CSP sandbox, stops Chrome from rendering it at all.
     */
    private const INLINE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function show(Media $media): StreamedResponse
    {
        $owner = $media->model;

        // Missing, trashed or foreign owners look the same as a media id that does not exist.
        RecordNotFound::abortUnless($owner instanceof Model && (int) $owner->getAttribute('company_id') === CompanyService::currentId());
        Gate::authorize('view', $owner);

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();

        abort_unless($disk->exists($path), 404);

        $mime = $media->mime_type ?: 'application/octet-stream';

        return $disk->response($path, $media->file_name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], in_array($mime, self::INLINE_TYPES, true) ? 'inline' : 'attachment');
    }
}
