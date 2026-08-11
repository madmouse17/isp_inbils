<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\NumberSequenceResource;
use App\Models\Core\NumberSequence;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NumberSequenceController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['entity_type', 'prefix', 'next_number', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', NumberSequence::class);

        $sequences = $this->filteredQuery($request)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/NumberSequences/Index', [
            'sequences' => NumberSequenceResource::collection($sequences),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
            'can' => [
                'export' => $request->user()?->can('system.setting') ?? false,
            ],
        ]);
    }

    public function update(Request $request, NumberSequence $number_sequence): RedirectResponse
    {
        Gate::authorize('update', $number_sequence);
        $request->validate([
            'prefix' => ['required', 'string', 'max:20'],
            'next_number' => ['required', 'integer', 'min:1'],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
            'year_suffix' => ['boolean'],
        ]);
        $number_sequence->update($request->only(['prefix', 'next_number', 'padding', 'year_suffix']));

        return back()->with('success', 'Number sequence updated.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('system.setting');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('entity_type', 'asc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'entity_type' => 'Entity',
            'prefix' => 'Prefix',
            'next_number' => 'Next Number',
            'padding' => 'Padding',
            'year_suffix' => 'Year Suffix',
        ];

        $map = static fn (NumberSequence $s): array => [
            'entity_type' => $s->entity_type,
            'prefix' => $s->prefix,
            'next_number' => $s->next_number,
            'padding' => $s->padding,
            'year_suffix' => $s->year_suffix ? 'Yes' : 'No',
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Number Sequences', $columns, $map, "number-sequences-export-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "number-sequences-export-{$stamp}.csv");
    }

    /**
     * @return Builder<NumberSequence>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = NumberSequence::query()
            ->when($request->input('search'), function (Builder $q, string $v): void {
                $term = trim($v);
                if ($term === '') {
                    return;
                }

                $like = '%'.$term.'%';
                $q->where(function (Builder $sq) use ($like): void {
                    $sq->where('entity_type', 'like', $like)
                        ->orWhere('prefix', 'like', $like);
                });
            });

        return $this->applySort($query, $request, 'entity_type');
    }
}
