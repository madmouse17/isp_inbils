<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentTypeResource;
use App\Models\Core\DocumentType;
use App\Services\Core\CompanyService;
use App\Services\Core\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DocumentController extends Controller
{
    public function index(): InertiaResponse
    {
        Gate::authorize('viewAny', DocumentType::class);
        $types = DocumentType::query()->orderBy('name')->paginate(10)->withQueryString();

        return Inertia::render('Admin/Documents/Index', [
            'documentTypes' => DocumentTypeResource::collection($types),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', DocumentType::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'applies_to' => ['nullable', 'string', 'max:100'],
            'is_required' => ['boolean'],
            'expiry_days' => ['nullable', 'integer', 'min:1'],
        ]);
        $data['company_id'] = CompanyService::currentId();
        DocumentType::create($data);

        return back()->with('success', 'Document type created.');
    }

    public function update(Request $request, DocumentType $document_type): RedirectResponse
    {
        Gate::authorize('update', $document_type);
        $document_type->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'applies_to' => ['nullable', 'string', 'max:100'],
            'is_required' => ['boolean'],
            'expiry_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ]));

        return back()->with('success', 'Document type updated.');
    }

    public function destroy(DocumentType $document_type): RedirectResponse
    {
        Gate::authorize('delete', $document_type);
        $document_type->delete();

        return back()->with('success', 'Document type deleted.');
    }

    public function uploadMedia(Request $request): RedirectResponse
    {
        $request->validate([
            'model_type' => ['required', 'string'],
            'model_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240'],
            'document_type_code' => ['nullable', 'string'],
            'collection' => ['nullable', 'string'],
        ]);

        $model = $request->input('model_type')::findOrFail($request->integer('model_id'));

        DocumentService::upload(
            $model,
            $request->file('file'),
            $request->input('document_type_code'),
            $request->input('collection', 'documents'),
        );

        return back()->with('success', 'Document uploaded.');
    }

    public function deleteMedia(Media $media): RedirectResponse
    {
        DocumentService::delete($media);

        return back()->with('success', 'Document removed.');
    }
}
