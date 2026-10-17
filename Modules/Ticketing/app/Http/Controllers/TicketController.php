<?php

namespace Modules\Ticketing\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Concerns\UploadsMedia;
use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Queries\Admin\DispatchQueueQuery;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use App\Support\PublicId;
use App\Support\RecordNotFound;
use App\Support\SearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Ticketing\Http\Requests\AssignTicketRequest;
use Modules\Ticketing\Http\Requests\ResolveTicketRequest;
use Modules\Ticketing\Http\Requests\StoreTicketCommentRequest;
use Modules\Ticketing\Http\Requests\StoreTicketRequest;
use Modules\Ticketing\Http\Requests\UpdateTicketRequest;
use Modules\Ticketing\Http\Requests\UploadTicketAttachmentRequest;
use Modules\Ticketing\Http\Resources\TicketCategoryResource;
use Modules\Ticketing\Http\Resources\TicketResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            'filters' => $request->only(['search', 'priority', 'status', 'source', 'category_id', 'assigned_to', 'sla_breached', 'sort_by', 'sort_dir', 'per_page']),
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

        $export = ExportQuery::make($this->applySort($query, $request, 'created_at', 'desc'))
            ->defaultSort('created_at', 'desc')
            ->maxRows(ExportQuery::resolveMaxRows(config('exports.max_rows', ExportQuery::DEFAULT_MAX_ROWS)));

        $columns = [
            'Kode' => 'code',
            'Judul' => 'title',
            'Prioritas' => 'priority',
            'Status' => 'status',
            'Pelanggan' => 'customer.name',
            'Dibuat' => 'created_at',
        ];

        $map = static function (Ticket $ticket): array {
            return [
                'Kode' => $ticket->code,
                'Judul' => $ticket->title,
                'Prioritas' => $ticket->priority,
                'Status' => $ticket->status,
                'Pelanggan' => data_get($ticket, 'customer.name', ''),
                'Dibuat' => optional($ticket->created_at)?->toDateTimeString(),
            ];
        };

        $stamp = now()->format('Ymd-His');
        $format = strtolower((string) $request->input('format', 'csv'));

        return $format === 'pdf'
            ? $export->downloadPdf('Tiket', $columns, $map, "tickets-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "tickets-export-{$stamp}.csv");
    }

    /**
     * @return Builder<Ticket>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Ticket::query()
            ->with(['category', 'customer', 'assignee'])
            ->when($request->input('priority'), fn (Builder $q, string $v) => $q->where('priority', $v))
            ->when($request->input('status'), fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($request->input('source'), fn (Builder $q, string $v) => $q->where('source', $v))
            ->when($request->input('category_id'), fn (Builder $q, string $v) => $q->where('category_id', PublicId::resolveOne(TicketCategory::class, $v) ?? 0))
            ->when($request->input('assigned_to'), fn (Builder $q, string $v) => $q->where('assigned_to', PublicId::resolveOne(User::class, $v) ?? 0))
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

        if (($user = request()->user()) !== null && $user->hasRole('customer')) {
            // fail closed: a portal user without a linked customer sees nothing
            $query->where('customer_id', $user->customer?->id ?? 0);
        }

        if (($user = request()->user()) !== null
            && ($user->can('ticket.start') || $user->can('ticket.resolve') || $user->can('ticket.close'))
            && ! $user->can('ticket.assign')) {
            if (! $user->is_active) {
                $query->whereIn('id', []);
            } else {
                $query->where('assigned_to', $user->id);
            }
        }

        return $query;
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', Ticket::class);

        $ownCustomerId = $request->user()->hasRole('customer') ? ($request->user()->customer?->id ?? 0) : null;
        // The page sends the customer and subscription it has picked, so what follows is offered only for them.
        $customerId = PublicId::resolveOne(Customer::class, $request->input('customer_id')) ?? 0;
        $subscriptionId = PublicId::resolveOne(ServiceSubscription::class, $request->input('subscription_id')) ?? 0;

        return Inertia::render('Admin/Tickets/Create', [
            'categories' => TicketCategoryResource::collection(TicketCategory::query()->where('is_active', true)->orderBy('name')->get()),
            'customerOptions' => SearchOptions::for(
                $request,
                'customer',
                Customer::query()->where('is_active', true)->when($ownCustomerId !== null, fn (Builder $q) => $q->whereKey($ownCustomerId)),
                ['name', 'code'],
                fn (Customer $customer): array => ['value' => $customer->public_id, 'label' => $customer->name, 'description' => $customer->code],
            ),
            'subscriptionOptions' => SearchOptions::for(
                $request,
                'subscription',
                ServiceSubscription::query()
                    ->with('customer:id,name')
                    ->whereIn('status', ['active', 'suspended'])
                    ->when($ownCustomerId !== null, fn (Builder $q) => $q->where('customer_id', $ownCustomerId))
                    ->when($customerId > 0, fn (Builder $q) => $q->where('customer_id', $customerId)),
                ['code'],
                fn (ServiceSubscription $subscription): array => ['value' => $subscription->public_id, 'label' => $subscription->code, 'description' => $subscription->customer?->name],
            ),
            // An asset of nobody (no customer, no subscription) can be reported against anyone, as StoreTicketRequest allows.
            'networkAssetOptions' => SearchOptions::for(
                $request,
                'networkAsset',
                NetworkAsset::query()
                    ->whereNotIn('status', ['retired', 'decommissioned'])
                    ->whereNull('retired_at')
                    ->when($ownCustomerId !== null, fn (Builder $q) => $q->where('customer_id', $ownCustomerId))
                    ->when($customerId > 0, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('customer_id')->orWhere('customer_id', $customerId)))
                    ->when($subscriptionId > 0, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('subscription_id')->orWhere('subscription_id', $subscriptionId))),
                ['code', 'name'],
                fn (NetworkAsset $asset): array => ['value' => $asset->public_id, 'label' => $asset->code.' - '.$asset->name],
            ),
            'canChooseLocation' => $ownCustomerId === null,
            'locations' => LocationResource::collection(
                $ownCustomerId === null
                    ? Location::query()->where('is_active', true)->orderBy('code')->get()
                    : []
            ),
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        Gate::authorize('store', Ticket::class);

        TicketService::create($request->ticketAttributes(), $request->user()->id);

        return redirect()->route('admin.tickets.index')
            ->with('success', 'Tiket berhasil dibuat.');
    }

    public function show(Request $request, Ticket $ticket): InertiaResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('view', $ticket);

        $hideInternal = (bool) request()->user()?->hasRole('customer');

        $ticket->load([
            'category', 'customer', 'subscription', 'networkAsset', 'location', 'assignee', 'media',
            'comments' => fn ($query) => $query->when($hideInternal, fn ($q) => $q->where('is_internal', false)),
            'comments.author',
        ]);

        return Inertia::render('Admin/Tickets/Show', [
            'ticket' => new TicketResource($ticket),
            // Who the ticket can be assigned to; empty unless the actor may assign or reassign.
            'handlers' => DispatchQueueQuery::eligibleHandlers($request),
        ]);
    }

    public function edit(Ticket $ticket): InertiaResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('edit', $ticket);
        abort_if($ticket->status !== 'open', 422, 'Tiket hanya bisa diubah saat berstatus terbuka.');

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
        abort_if($ticket->status !== 'open', 422, 'Tiket hanya bisa diubah saat berstatus terbuka.');

        $ticket->update($request->validated());

        return redirect()->route('admin.tickets.index')->with('success', 'Tiket berhasil diubah.');
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('delete', $ticket);
        abort_if($ticket->status !== 'closed', 422, 'Tiket hanya bisa dihapus saat berstatus ditutup.');

        $ticket->delete();

        return back()->with('success', 'Tiket berhasil dihapus.');
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('assign', $ticket);

        $expectedAssignedTo = $request->input('expected_assigned_to') !== null
            ? (int) $request->input('expected_assigned_to')
            : null;
        $expectedUpdatedAt = (string) $request->input('expected_updated_at');

        try {
            TicketService::assign(
                $ticket,
                $request->integer('handler_id'),
                $request->user()->id,
                $expectedAssignedTo,
                $expectedUpdatedAt
            );
        } catch (HttpException $exception) {
            if ($request->header('X-Inertia') && $exception->getStatusCode() === 409) {
                return back()->withErrors(['conflict' => $exception->getMessage()]);
            }

            throw $exception;
        }

        return back()->with('success', 'Tiket berhasil ditugaskan.');
    }

    public function start(Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('start', $ticket);

        try {
            TicketService::startWork($ticket);
        } catch (HttpException $exception) {
            if (request()->header('X-Inertia') && $exception->getStatusCode() === 409) {
                return back()->withErrors(['conflict' => $exception->getMessage()]);
            }

            throw $exception;
        }

        return back()->with('success', 'Tiket mulai dikerjakan.');
    }

    public function resolve(ResolveTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('resolve', $ticket);

        try {
            TicketService::resolve($ticket, $request->input('resolution_note'));
        } catch (HttpException $exception) {
            if ($request->header('X-Inertia') && $exception->getStatusCode() === 409) {
                return back()->withErrors(['conflict' => $exception->getMessage()]);
            }

            throw $exception;
        }

        return back()->with('success', 'Tiket diselesaikan.');
    }

    public function close(Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('close', $ticket);
        try {
            TicketService::close($ticket);
        } catch (HttpException $exception) {
            if (request()->header('X-Inertia') && $exception->getStatusCode() === 409) {
                return back()->withErrors(['conflict' => $exception->getMessage()]);
            }

            throw $exception;
        }

        return back()->with('success', 'Tiket ditutup.');
    }

    public function spawnSpk(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('spawnSpk', $ticket);

        $workOrder = TicketService::spawnSpk($ticket);

        return redirect()->route('admin.spk.show', $workOrder)
            ->with('success', 'SPK berhasil dibuat dari tiket.');
    }

    public function addComment(StoreTicketCommentRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        // A ticket the page hides from this user is as good as unknown to them: 404 before the ability to comment.
        Gate::authorize('view', $ticket);
        Gate::authorize('comment', $ticket);

        TicketService::addComment($ticket, $request->validated(), $request->user()->id);

        return back()->with('success', 'Komentar ditambahkan.');
    }

    public function uploadAttachment(UploadTicketAttachmentRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('view', $ticket);
        Gate::authorize('attach', $ticket);

        $file = $request->file('file');
        $this->storeMedia($ticket, $file, 'attachments', [
            'company_id' => $ticket->company_id,
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Lampiran berhasil diunggah.');
    }

    public function removeAttachment(Request $request, Ticket $ticket, string $attachment): RedirectResponse
    {
        $this->ensureSameCompany($ticket);

        // One rule with the apps: the uploader, while the ticket is still being worked. The permission alone is not enough.
        TicketService::removeAttachment($ticket, $attachment, $request->user());

        return back()->with('success', 'Lampiran berhasil dihapus.');
    }

    private function ensureSameCompany(Ticket $ticket): void
    {
        RecordNotFound::abortUnless($ticket->company_id === CompanyService::currentId());
    }
}
