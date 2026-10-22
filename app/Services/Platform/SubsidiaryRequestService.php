<?php

namespace App\Services\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Notifications\Platform\SubsidiaryRequestedMail;
use App\Notifications\Platform\SubsidiaryRequestRejectedMail;
use App\Services\Core\AuditService;
use App\Support\RecordNotFound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * A tenant's request for a subsidiary (K34). The request is a platform audit row, not a table (OD12): the tenant admin
 * writes `tenant.subsidiary_requested` (name, code, note, `requested_by` = user id) about the tenant's top-level company,
 * and an operator closes it with `tenant.subsidiary_request_closed` (`request_id` = the request row's public id, `name`,
 * `outcome` created|rejected, `reason`). A request is open until a closing row names it.
 *
 * Every write locks the tenant's company row first, so two admins cannot open two requests and two operators cannot
 * close one twice (one creates while the other rejects): the second finds the first one's row. A mail that cannot be
 * queued is reported and never undoes the row, which is the record.
 */
class SubsidiaryRequestService
{
    public const REQUESTED = 'tenant.subsidiary_requested';

    public const CLOSED = 'tenant.subsidiary_request_closed';

    /** shortcut: the dashboard lists the oldest requests first and no more than this; a tenant holds one open request, so it is far above any real count. Page the list if a platform ever exceeds it. */
    private const LISTED = 50;

    public function __construct(private readonly PlatformAuditService $audit) {}

    /**
     * Records the request of $requester for $company and tells every active operator. Refused while the company has
     * one open.
     *
     * @param  array{name: string, code?: ?string, note?: ?string}  $validated
     */
    public function submit(User $requester, Company $company, array $validated): void
    {
        $row = DB::transaction(function () use ($requester, $company, $validated): Activity {
            $head = Company::query()->lockForUpdate()->findOrFail($company->id);

            if ($this->open($head)->exists()) {
                throw ValidationException::withMessages(['pending' => 'Masih ada permintaan anak perusahaan yang ditinjau operator. Tunggu sampai selesai sebelum mengajukan lagi.']);
            }

            $row = AuditService::log(PlatformAuditService::PLATFORM_LOG, self::REQUESTED, [
                'name' => $validated['name'],
                'code' => $validated['code'] ?? null,
                'note' => $validated['note'] ?? null,
                'requested_by' => $requester->id,
            ], $head, causer: $requester);

            // The row is the only record of the request: with activity logging off there would be none, and "sent" would be false.
            abort_if($row === null, 503, 'Pencatatan aktivitas dimatikan, jadi permintaan tidak dapat dicatat.');

            /** @var Activity $row */
            return $row;
        });

        $this->tellOperators($row, $company);
    }

    /** Closes the request because the operator created the subsidiary. Runs inside the transaction that creates it. */
    public function approve(PlatformAdmin $actor, Company $root, string $requestId): void
    {
        DB::transaction(fn () => $this->close($actor, $root, $requestId, 'created', null));
    }

    /**
     * Closes the request with the operator's reason and mails that reason to whoever asked.
     *
     * @return bool false when nobody could be mailed (the person is gone or inactive, or the mail could not be queued): the operator passes the reason on by hand
     */
    public function reject(PlatformAdmin $actor, Company $root, string $requestId, string $reason): bool
    {
        [$request, $closing] = DB::transaction(fn (): array => $this->close($actor, $root, $requestId, 'rejected', $reason));

        return $this->tellRequester($request, $closing, $root);
    }

    /** The open request of this tenant with this public id, or null: unknown, another tenant's, or closed already. */
    public function findOpen(Company $root, string $requestId): ?Activity
    {
        return Str::isUlid($requestId) ? $this->open($root)->where('public_id', $requestId)->first() : null;
    }

    /**
     * The open requests, oldest first, of one tenant or of all of them: what the operator's pages list. A request
     * whose tenant is gone is left out.
     *
     * @return list<array{id: string, name: string, code: ?string, note: ?string, requested_at: ?string, requester: ?array{name: string}, company: array{id: string, name: string, code: string}}>
     */
    public function pending(?Company $root = null): array
    {
        $rows = $this->open($root)->limit(self::LISTED)->get(['id', 'public_id', 'company_id', 'properties', 'created_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        $companies = Company::query()->whereIn('id', $rows->pluck('company_id')->filter()->unique())->get(['id', 'public_id', 'name', 'code'])->keyBy('id');
        $requesters = User::query()
            ->whereIn('id', $rows->map(fn (Activity $row): mixed => $this->property($row, 'requested_by'))->filter(fn (mixed $id): bool => is_int($id))->unique())
            ->pluck('name', 'id');

        return $rows
            ->filter(fn (Activity $row): bool => ($companyId = $this->companyId($row)) !== null && $companies->has($companyId))
            ->map(function (Activity $row) use ($companies, $requesters): array {
                $company = $companies->get($this->companyId($row));
                $userId = $this->property($row, 'requested_by');
                $requester = is_int($userId) ? $requesters->get($userId) : null;

                return [
                    ...$this->summary($row),
                    'id' => $this->publicId($row),
                    'requester' => is_string($requester) ? ['name' => $requester] : null,
                    'company' => ['id' => $company->public_id, 'name' => $company->name, 'code' => $company->code],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * What the company's own profile page says about its open request: the tenant reads its own text, never who handles it.
     *
     * @return array{name: string, code: ?string, note: ?string, requested_at: ?string}|null
     */
    public function summaryFor(Company $company): ?array
    {
        $row = $this->open($company)->first();

        return $row === null ? null : $this->summary($row);
    }

    /**
     * The requests nobody closed: no closing row of the same tenant names them. The closing row is matched on the
     * tenant's id too, so the lookup stays on the (company_id, log_name) index.
     *
     * @return Builder<Activity>
     */
    private function open(?Company $root = null): Builder
    {
        $table = (new Activity())->getTable();

        return $this->requests($root)->whereNotExists(fn ($closed) => $closed
            ->selectRaw('1')
            ->from($table.' as closed')
            ->where('closed.log_name', PlatformAuditService::PLATFORM_LOG)
            ->where('closed.description', self::CLOSED)
            ->whereColumn('closed.company_id', $table.'.company_id')
            ->whereColumn('closed.properties->request_id', $table.'.public_id'));
    }

    /**
     * Every request row, open or closed, oldest first.
     *
     * @return Builder<Activity>
     */
    private function requests(?Company $root = null): Builder
    {
        return Activity::query()
            ->where('log_name', PlatformAuditService::PLATFORM_LOG)
            ->where('description', self::REQUESTED)
            ->when($root !== null, fn (Builder $rows) => $rows->where('company_id', $root?->id))
            ->orderBy('id');
    }

    private function property(Activity $row, string $key): mixed
    {
        return $row->properties?->get($key);
    }

    /** The package model does not declare `public_id` (the Activity creating hook fills it) or `company_id`. */
    private function publicId(Activity $row): string
    {
        return (string) $row->getAttribute('public_id');
    }

    private function companyId(Activity $row): ?int
    {
        $id = $row->getAttribute('company_id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{name: string, code: ?string, note: ?string, requested_at: ?string} */
    private function summary(Activity $row): array
    {
        $text = function (string $key) use ($row): ?string {
            $value = $this->property($row, $key);

            return is_string($value) && $value !== '' ? $value : null;
        };

        return [
            'name' => $text('name') ?? '',
            'code' => $text('code'),
            'note' => $text('note'),
            'requested_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * Writes the closing row under the tenant's lock. A request that is no row of this tenant is a 404, whoever asks; a
     * closed one is a refusal, so the second operator is told instead of closing it twice.
     *
     * @return array{0: Activity, 1: Activity} the request and its closing row
     */
    private function close(PlatformAdmin $actor, Company $root, string $requestId, string $outcome, ?string $reason): array
    {
        $head = Company::query()->lockForUpdate()->findOrFail($root->id);
        $request = Str::isUlid($requestId) ? $this->requests($head)->where('public_id', $requestId)->first() : null;
        $request ??= RecordNotFound::abort();

        if (! $this->open($head)->whereKey($request->id)->exists()) {
            throw ValidationException::withMessages(['subsidiary_request_id' => 'Permintaan ini sudah ditutup. Muat ulang halaman untuk melihat yang terbaru.']);
        }

        $closing = $this->audit->platform(self::CLOSED, [
            'request_id' => $this->publicId($request),
            'name' => $this->property($request, 'name'),
            'outcome' => $outcome,
            'reason' => $reason,
        ], $head, $actor);

        abort_if($closing === null, 503, 'Pencatatan aktivitas dimatikan, jadi permintaan tidak dapat ditutup.');

        /** @var Activity $closing */
        return [$request, $closing];
    }

    /** One mail per active operator, so nobody's address sits in another's To line. */
    private function tellOperators(Activity $request, Company $company): void
    {
        foreach (PlatformAdmin::query()->where('is_active', true)->get(['name', 'email']) as $operator) {
            rescue(fn () => Notification::route('mail', [$operator->email => $operator->name])
                ->notify(new SubsidiaryRequestedMail((int) $company->id, $this->publicId($request))));
        }
    }

    private function tellRequester(Activity $request, Activity $closing, Company $root): bool
    {
        $userId = $this->property($request, 'requested_by');
        $requester = is_int($userId) ? User::query()->whereKey($userId)->where('is_active', true)->first() : null;

        if ($requester === null) {
            return false;
        }

        return rescue(function () use ($requester, $closing, $root): bool {
            $requester->notify(new SubsidiaryRequestRejectedMail((int) $root->id, $this->publicId($closing)));

            return true;
        }, false);
    }
}
