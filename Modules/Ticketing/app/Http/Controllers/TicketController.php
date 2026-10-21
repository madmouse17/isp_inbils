<?php

namespace Modules\Ticketing\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Queries\Admin\DispatchQueueQuery;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use App\Support\OptimisticLock;
use App\Support\PublicId;
use App\Support\RecordNotFound;
use App\Support\SearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Ticketing\Http\Requests\AssignTicketRequest;
use Modules\Ticketing\Http\Requests\ReopenTicketRequest;
use Modules\Ticketing\Http\Requests\ResolveTicketRequest;
use Modules\Ticketing\Http\Requests\SlaPreviewTicketRequest;
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

    private const SORTABLE = ['code', 'title', 'source', 'status', 'priority', 'sla_deadline', 'response_deadline', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        $tickets = $this->applySort($this->filteredQuery($request), $request, 'created_at', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Tickets/Index', [
            'tickets' => TicketResource::collection($tickets),
            'filters' => $request->only(['search', 'priority', 'status', 'source', 'category_id', 'assigned_to', 'sla_breached', 'unassigned', 'due_soon', 'awaiting_reply', 'sort_by', 'sort_dir', 'per_page']),
            'can' => [
                'create' => $request->user()?->can('ticket.create') ?? false,
                'update' => $request->user()?->can('ticket.update') ?? false,
                'delete' => $request->user()?->can('ticket.delete') ?? false,
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
            'Petugas' => 'assignee.name',
            'Respons sebelum' => 'response_deadline',
            'Dibuat' => 'created_at',
        ];

        $map = static function (Ticket $ticket): array {
            return [
                'Kode' => $ticket->code,
                'Judul' => $ticket->title,
                'Prioritas' => $ticket->priority,
                'Status' => $ticket->status,
                'Pelanggan' => data_get($ticket, 'customer.name', ''),
                'Petugas' => $ticket->assignee->name ?? '',
                'Respons sebelum' => $ticket->response_deadline?->toDateTimeString(),
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
            ->lifecycleStatus($request->input('status'))
            ->when($request->input('source'), fn (Builder $q, string $v) => $q->where('source', $v))
            ->when($request->input('category_id'), fn (Builder $q, string $v) => $q->where('category_id', PublicId::resolveOne(TicketCategory::class, $v) ?? 0))
            ->when($request->input('assigned_to'), fn (Builder $q, string $v) => $q->where('assigned_to', PublicId::resolveOne(User::class, $v) ?? 0))
            ->when($request->boolean('sla_breached'), fn (Builder $q) => $q->where('sla_deadline', '<', now())->whereNotIn('status', Ticket::FINISHED_STATUSES))
            ->when($request->boolean('unassigned'), fn (Builder $q) => $q->whereNull('assigned_to')->whereNotIn('status', Ticket::FINISHED_STATUSES))
            ->when($request->boolean('due_soon'), fn (Builder $q) => $q->dueSoon())
            ->when($request->boolean('awaiting_reply'), fn (Builder $q) => $q->awaitingStaffReply())
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

        if (($user = request()->user()) !== null && $user->can('portal.access')) {
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

        $ownCustomerId = $request->user()->can('portal.access') ? ($request->user()->customer?->id ?? 0) : null;
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

    /**
     * What a ticket with this category (and subscription, and priority) would be held to, for the create form before anything is
     * saved: the deadlines and the source the ticket gets when it is made.
     */
    public function slaPreview(SlaPreviewTicketRequest $request): JsonResponse
    {
        Gate::authorize('create', Ticket::class);

        $ticket = new Ticket($request->safe()->only(['category_id', 'subscription_id', 'priority']));
        $deadlines = TicketService::computeDeadlines($ticket);
        $source = TicketService::deadlineSource($ticket);

        return response()->json([
            'response_due' => $deadlines['response']?->toIso8601String(),
            'resolution_due' => $deadlines['resolution']->toIso8601String(),
            'source' => $source,
            // Every target counts calendar hours until a tier can count the company's service hours.
            'calendar' => $source['calendar'] ?? '24h',
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

        $hideInternal = (bool) request()->user()?->can('portal.access');

        $relations = [
            'category', 'customer.user:id,public_id', 'subscription', 'networkAsset', 'location', 'assignee', 'media',
            'comments' => fn ($query) => $query->when($hideInternal, fn ($q) => $q->where('is_internal', false)),
            'comments.author',
        ];

        if ($request->user()?->can('customer.view')) {
            // One address is all the page reads: where the service is installed, else the primary one.
            $relations['customer.addresses'] = fn ($query) => $query->orderByDesc('is_installation_point')->orderByDesc('is_primary')->orderBy('id')->limit(1);
        }

        $ticket->load($relations);

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

        TicketService::update($ticket, $request->safe()->except(OptimisticLock::FIELD), $request->validated(OptimisticLock::FIELD));

        return redirect()->route('admin.tickets.index')->with('success', 'Tiket berhasil diubah.');
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('delete', $ticket);

        TicketService::destroy($ticket);

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

    /** "Tugaskan ke saya": the actor takes an unassigned ticket. Somebody else being quicker is a 409, as for the dialog. */
    public function assignSelf(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('assignSelf', $ticket);

        try {
            TicketService::assignSelf($ticket, $request->user());
        } catch (HttpException $exception) {
            if ($request->header('X-Inertia') && $exception->getStatusCode() === 409) {
                return back()->withErrors(['conflict' => $exception->getMessage()]);
            }

            throw $exception;
        }

        return back()->with('success', "Tiket {$ticket->code} ditugaskan ke Anda.");
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

    public function reopen(ReopenTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('reopen', $ticket);

        TicketService::reopenByStaff($ticket, $request->user(), $request->validated('reason'));

        return back()->with('success', "Tiket {$ticket->code} dibuka kembali.");
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
        TicketService::addAttachment(
            $ticket,
            $request->user(),
            ['file' => $file, 'caption' => $request->validated('caption')],
            // The same file twice on one ticket is one attachment, however far apart the two posts are.
            hash('sha256', (string) hash_file('sha256', $file->getRealPath())),
        );

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
