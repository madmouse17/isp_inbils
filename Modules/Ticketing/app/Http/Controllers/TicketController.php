<?php

namespace Modules\Ticketing\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Concerns\UploadsMedia;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\UserResource;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\NetworkAsset\Http\Resources\NetworkAssetResource;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Ticketing\Http\Requests\AssignTicketRequest;
use Modules\Ticketing\Http\Requests\StoreTicketRequest;
use Modules\Ticketing\Http\Requests\UpdateTicketRequest;
use Modules\Ticketing\Http\Resources\TicketCategoryResource;
use Modules\Ticketing\Http\Resources\TicketResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Models\TicketComment;
use Modules\Ticketing\Services\TicketService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    use HasIndexQuery;
    use UploadsMedia;

    private const SORTABLE = ['code', 'title', 'source', 'status', 'priority', 'sla_deadline', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        $tickets = $this->applySort($this->filteredQuery($request), $request, 'created_at', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Tickets/Index', [
            'tickets' => TicketResource::collection($tickets),
            'categories' => TicketCategoryResource::collection(TicketCategory::query()->where('is_active', true)->orderBy('name')->get()),
            'handlers' => UserResource::collection(
                User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'manager', 'noc', 'staff', 'technician']))
                    ->where('is_active', true)->orderBy('name')->get()
            ),
            'filters' => $request->only(['search', 'status', 'source', 'category_id', 'assigned_to', 'sla_breached', 'sort_by', 'sort_dir', 'per_page']),
            'can' => [
                'create' => $request->user()?->can('ticket.create') ?? false,
                'export' => $request->user()?->can('ticket.export') ?? false,
            ],
        ]);
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        abort_unless($request->user()?->can('ticket.export') ?? false, 403);

        $query = $this->filteredQuery($request)->with(['customer:id,code,name']);

        $export = ExportQuery::make($query)
            ->for(
                $request,
                self::SORTABLE,
                ['code', 'title'],
                'created_at',
                'desc'
            )
            ->maxRows(ExportQuery::resolveMaxRows(config('exports.max_rows', ExportQuery::DEFAULT_MAX_ROWS)));

        $columns = [
            'Code' => 'code',
            'Title' => 'title',
            'Priority' => 'priority',
            'Status' => 'status',
            'Customer' => 'customer.name',
            'Created' => 'created_at',
        ];

        $map = static function (Ticket $ticket): array {
            return [
                'Code' => $ticket->code,
                'Title' => $ticket->title,
                'Priority' => $ticket->priority,
                'Status' => $ticket->status,
                'Customer' => $ticket->customer?->name,
                'Created' => optional($ticket->created_at)?->toDateTimeString(),
            ];
        };

        $stamp = now()->format('Ymd-His');
        $format = strtolower((string) $request->input('format', 'csv'));

        return $format === 'pdf'
            ? $export->downloadPdf('Tickets', $columns, $map, "tickets-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "tickets-export-{$stamp}.csv");
    }

    /**
     * @return Builder<Ticket>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Ticket::query()
            ->with(['category', 'customer', 'assignee'])
            ->when($request->input('status'), fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($request->input('source'), fn (Builder $q, string $v) => $q->where('source', $v))
            ->when($request->input('category_id'), fn (Builder $q, string $v) => $q->where('category_id', $v))
            ->when($request->input('assigned_to'), fn (Builder $q, string $v) => $q->where('assigned_to', $v))
            ->when($request->boolean('sla_breached'), fn (Builder $q) => $q->where('sla_deadline', '<', now())->whereNotIn('status', ['resolved', 'closed']))
            ->when($request->input('search'), function (Builder $q, string $v): void {
                $term = trim($v);

                if ($term === '') {
                    return;
                }

                $like = '%'.$term.'%';
                $q->where(function (Builder $sq) use ($like): void {
                    $sq->where('code', 'like', $like)
                        ->orWhere('title', 'like', $like);
                });
            });

        return $this->applySort($query, $request, 'created_at', 'desc');
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', Ticket::class);

        return Inertia::render('Admin/Tickets/Create', [
            'categories' => TicketCategoryResource::collection(TicketCategory::query()->where('is_active', true)->orderBy('name')->get()),
            'customers' => CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'subscriptions' => SubscriptionResource::collection(ServiceSubscription::query()->whereIn('status', ['active', 'suspended'])->orderBy('code')->get()),
            'assets' => NetworkAssetResource::collection(NetworkAsset::query()->orderBy('code')->get()),
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        Gate::authorize('store', Ticket::class);

        TicketService::create($request->validated(), $request->user()->id);

        return redirect()->route('admin.tickets.index')
            ->with('success', 'Ticket created.');
    }

    public function show(Ticket $ticket): InertiaResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('view', $ticket);

        $ticket->load(['category', 'customer', 'subscription', 'networkAsset', 'location', 'assignee', 'comments.author', 'media']);

        return Inertia::render('Admin/Tickets/Show', [
            'ticket' => new TicketResource($ticket),
        ]);
    }

    public function edit(Ticket $ticket): InertiaResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('edit', $ticket);
        abort_if($ticket->status !== 'open', 422, 'Can only edit open tickets.');

        $ticket->load(['category', 'customer', 'subscription', 'networkAsset', 'location']);

        return Inertia::render('Admin/Tickets/Edit', [
            'ticket' => new TicketResource($ticket),
            'categories' => TicketCategoryResource::collection(TicketCategory::query()->where('is_active', true)->orderBy('name')->get()),
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('update', $ticket);
        abort_if($ticket->status !== 'open', 422, 'Can only edit open tickets.');

        $ticket->update($request->validated());

        return redirect()->route('admin.tickets.index')->with('success', 'Ticket updated.');
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('delete', $ticket);
        abort_if($ticket->status !== 'closed', 422, 'Can only delete closed tickets.');

        $ticket->delete();

        return back()->with('success', 'Ticket deleted.');
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('ticket.assign');

        TicketService::assign($ticket, $request->integer('handler_id'), $request->user()->id);

        return back()->with('success', 'Ticket assigned.');
    }

    public function start(Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('ticket.start');
        TicketService::startWork($ticket);

        return back()->with('success', 'Ticket started.');
    }

    public function resolve(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('ticket.resolve');

        $request->validate(['resolution_note' => ['required', 'string', 'max:1000']]);
        TicketService::resolve($ticket, $request->input('resolution_note'));

        return back()->with('success', 'Ticket resolved.');
    }

    public function close(Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('ticket.close');
        TicketService::close($ticket);

        return back()->with('success', 'Ticket closed.');
    }

    public function spawnSpk(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('ticket.spawn_spk');

        TicketService::spawnSpk($ticket);

        return redirect()->route('admin.spk.index')
            ->with('success', 'SPK spawned from ticket.');
    }

    public function addComment(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('ticket.comment.create');

        $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'is_internal' => ['boolean'],
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'author_id' => $request->user()->id,
            'body' => $request->input('body'),
            'is_internal' => $request->boolean('is_internal'),
        ]);

        return back()->with('success', 'Comment added.');
    }

    public function uploadAttachment(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('ticket.attachment.upload');

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx,txt', 'mimetypes:image/jpeg,image/png,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ]);

        $file = $request->file('file');
        $this->storeMedia($ticket, $file, 'attachments', [
            'company_id' => $ticket->company_id,
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Attachment uploaded.');
    }

    public function removeAttachment(Ticket $ticket, Media $attachment): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        abort_unless($attachment->model_type === $ticket::class && (int) $attachment->model_id === $ticket->id, 404);
        abort_unless($attachment->collection_name === 'attachments', 404);
        Gate::authorize('ticket.attachment.upload');

        $attachment->delete();

        return back()->with('success', 'Attachment removed.');
    }

    private function ensureSameCompany(Ticket $ticket): void
    {
        abort_unless($ticket->company_id === CompanyService::currentId(), 404);
    }
}
