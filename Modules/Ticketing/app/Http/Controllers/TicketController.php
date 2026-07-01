<?php

namespace Modules\Ticketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Ticketing\Http\Requests\StoreTicketRequest;
use Modules\Ticketing\Http\Requests\UpdateTicketRequest;
use Modules\Ticketing\Http\Resources\TicketCategoryResource;
use Modules\Ticketing\Http\Resources\TicketResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketAttachment;
use Modules\Ticketing\Models\TicketComment;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;

class TicketController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        $tickets = Ticket::query()
            ->with(['category', 'customer', 'assignee'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('category_id'), fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->input('assigned_to'), fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($request->input('sla_breached') === 'true', fn ($q) => $q->where('sla_deadline', '<', now())->whereNotIn('status', ['resolved', 'closed']))
            ->when($request->input('search'), fn ($q, $v) => $q->where(fn ($sq) => $sq
                ->where('code', 'like', "%{$v}%")
                ->orWhere('title', 'like', "%{$v}%")))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Tickets/Index', [
            'tickets' => TicketResource::collection($tickets),
            'categories' => TicketCategoryResource::collection(TicketCategory::query()->where('is_active', true)->orderBy('name')->get()),
            'handlers' => \App\Http\Resources\UserResource::collection(
                User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'manager', 'noc', 'staff', 'technician']))
                    ->where('is_active', true)->orderBy('name')->get()
            ),
            'filters' => $request->only(['status', 'source', 'category_id', 'assigned_to', 'sla_breached', 'search']),
            'can' => ['create' => $request->user()?->can('ticket.create') ?? false],
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', Ticket::class);

        return Inertia::render('Admin/Tickets/Create', [
            'categories' => TicketCategoryResource::collection(TicketCategory::query()->where('is_active', true)->orderBy('name')->get()),
            'customers' => \App\Http\Resources\CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'subscriptions' => \App\Http\Resources\SubscriptionResource::collection(ServiceSubscription::query()->whereIn('status', ['active', 'suspended'])->orderBy('code')->get()),
            'assets' => \Modules\NetworkAsset\Http\Resources\NetworkAssetResource::collection(NetworkAsset::query()->where('is_active', true)->orderBy('code')->get()),
            'locations' => \App\Http\Resources\LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        Gate::authorize('store', Ticket::class);

        $data = $request->validated();
        $data['code'] = TicketService::generateCode();
        $data['status'] = 'open';
        $data['created_by'] = $request->user()->id;

        $category = TicketCategory::find($data['category_id']);
        $slaHours = $category?->default_sla_hours ?? 24;
        if (isset($data['priority']) && $data['priority'] === 'urgent') {
            $slaHours = (int) ceil($slaHours / 2);
        }
        $data['sla_deadline'] = now()->addHours($slaHours);

        $ticket = Ticket::create($data);

        return redirect()->route('admin.tickets.show', $ticket)
            ->with('success', 'Ticket created.');
    }

    public function show(Ticket $ticket): InertiaResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('view', $ticket);

        $ticket->load(['category', 'customer', 'subscription', 'networkAsset', 'location', 'assignee', 'comments.author', 'attachments']);

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

        return back()->with('success', 'Ticket updated.');
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('delete', $ticket);
        abort_if($ticket->status !== 'closed', 422, 'Can only delete closed tickets.');

        $ticket->delete();

        return back()->with('success', 'Ticket deleted.');
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        Gate::authorize('ticket.assign');

        $request->validate(['handler_id' => ['required', 'exists:users,id']]);
        TicketService::assign($ticket, $request->integer('handler_id'), $request->user()->id);

        return back()->with('success', 'Ticket assigned.');
    }

    public function start(Request $request, Ticket $ticket): RedirectResponse
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

    public function close(Request $request, Ticket $ticket): RedirectResponse
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

        $wo = TicketService::spawnSpk($ticket);

        return redirect()->route('admin.spk.show', $wo)
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
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx,txt'],
        ]);

        $file = $request->file('file');
        $path = $file->store("tickets/{$ticket->id}", 'public');

        TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Attachment uploaded.');
    }

    public function removeAttachment(Ticket $ticket, TicketAttachment $attachment): RedirectResponse
    {
        $this->ensureSameCompany($ticket);
        abort_unless($attachment->ticket_id === $ticket->id, 404);
        Gate::authorize('ticket.attachment.upload');

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();

        return back()->with('success', 'Attachment removed.');
    }

    private function ensureSameCompany(Ticket $ticket): void
    {
        abort_unless($ticket->company_id === \App\Services\Core\CompanyService::currentId(), 404);
    }
}
