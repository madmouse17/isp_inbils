<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentTypeResource;
use App\Models\Core\DocumentType;
use App\Services\Core\CompanyService;
use App\Services\Core\DocumentService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'code', 'applies_to', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', DocumentType::class);
        $types = $this->filteredQuery($request)->paginate(10)->withQueryString();

        return Inertia::render('Admin/Documents/Index', [
            'documentTypes' => DocumentTypeResource::collection($types),
            'filters' => $request->only(['search', 'sort', 'direction']),
            'can' => ['export' => (bool) ($request->user()?->can('document.export'))],
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

    public function export(Request $request): HttpResponse|StreamedResponse
    {
        Gate::authorize('document.export');

        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('name', 'asc')
            ->fromRequest($request)
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'code' => 'Code',
            'name' => 'Name',
            'applies_to' => 'Applies To',
            'is_required' => 'Required',
            'expiry_days' => 'Expiry Days',
            'is_active' => 'Status',
        ];

        $map = static fn (DocumentType $type): array => [
            'code' => $type->code,
            'name' => $type->name,
            'applies_to' => $type->applies_to ?? '-',
            'is_required' => $type->is_required ? 'Yes' : 'No',
            'expiry_days' => $type->expiry_days ?? '-',
            'is_active' => $type->is_active ? 'Active' : 'Inactive',
        ];

        $filename = 'document-types-export-'.now()->format('Ymd-His');

        return strtolower((string) $request->input('format', 'csv')) === 'pdf'
            ? $export->streamPdf('Document Types', $columns, $map, $filename.'.pdf')
            : $export->streamCsv($columns, $map, $filename.'.csv');
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

    /** @return Builder<DocumentType> */
    private function filteredQuery(Request $request): Builder
    {
        $query = DocumentType::query()
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('name', 'like', $term)->orWhere('code', 'like', $term)->orWhere('applies_to', 'like', $term);
                });
            });

        return $this->applySort($query, $request, 'name');
    }
}
