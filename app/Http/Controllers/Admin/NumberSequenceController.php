<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateNumberSequenceRequest;
use App\Http\Resources\NumberSequenceResource;
use App\Models\Core\NumberSequence;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\ExportQuery;
use App\Support\NumberSequences\NumberSequenceDefinition;
use App\Support\NumberSequences\NumberSequenceRegistry;
use App\Support\NumberSequences\NumberTemplate;
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

    /** Stored `reset_period` as the export says it (`lib/statuses/numbering.ts` RESET_PERIOD on screen). */
    private const RESET_LABELS = [
        'never' => 'Tidak pernah',
        'yearly' => 'Setiap tahun',
        'monthly' => 'Setiap bulan',
        'daily' => 'Setiap hari',
    ];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', NumberSequence::class);

        NumberSequenceService::ensureAll(CompanyService::currentId() ?? abort(403, 'Konteks perusahaan diperlukan.'));

        $rows = NumberSequence::query()->with('company:id,code,timezone')->get()->keyBy('entity_type');
        $sequences = collect(app(NumberSequenceRegistry::class)->all())
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (NumberSequenceDefinition $definition): array => (new NumberSequenceResource($rows[$definition->entityType]))->resolve($request))
            ->values()
            ->all();

        return Inertia::render('Admin/NumberSequences/Index', [
            'sequences' => $sequences,
            'company_code' => (string) CompanyService::current()?->code,
            'tokens' => NumberTemplate::tokens(),
            'can' => [
                'export' => $request->user()?->can('system.setting') ?? false,
            ],
        ]);
    }

    public function update(UpdateNumberSequenceRequest $request, NumberSequence $number_sequence): RedirectResponse
    {
        NumberSequenceService::update($number_sequence, $request->validated());

        return back()->with('success', 'Penomoran diperbarui.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('system.setting');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $definitions = app(NumberSequenceRegistry::class)->all();
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('entity_type', 'asc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'entity_type' => 'Entitas',
            'prefix' => 'Awalan',
            'template' => 'Format',
            'next_number' => 'Nomor berikutnya',
            'reset_period' => 'Reset nomor',
            'allow_manual' => 'Input manual',
        ];

        $map = static fn (NumberSequence $s): array => [
            'entity_type' => ($definitions[$s->entity_type] ?? null)?->label ?? $s->entity_type,
            'prefix' => $s->prefix,
            'template' => $s->template,
            'next_number' => $s->next_number,
            'reset_period' => self::RESET_LABELS[$s->reset_period->value] ?? $s->reset_period->value,
            'allow_manual' => $s->allow_manual ? 'Boleh' : 'Tidak',
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Penomoran', $columns, $map, "number-sequences-export-{$stamp}.pdf");
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
