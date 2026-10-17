<?php

namespace App\Services\Platform;

use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suspend, reactivate and request deletion of a tenant: a head company with its subsidiaries. A subsidiary is never
 * a target. What a suspension does to people (login refused, requests fall back or sign out, API 403) is enforced by
 * code that already exists and keys off `companies.is_active`; this service only flips that flag the way the
 * platform may: each company is saved as a model, so `Company::updated` drops the memoised company resolution and
 * revokes the app tokens of the users it strands.
 *
 * Every action locks the tenant's rows, re-checks its precondition under the lock and answers a refusal with a
 * ValidationException on the `lifecycle` key. Each writes a platform audit row and, because the tenant may see who
 * touched its account, a `support` row per affected company (reason only: never the operator's email, IP or agent).
 */
class TenantLifecycleService
{
    /** Days a tenant must have been suspended before the platform accepts a request to delete it. */
    public const DELETION_AFTER_DAYS = 30;

    public function __construct(private readonly PlatformAuditService $audit) {}

    /** Deactivates the head and its active subsidiaries. Inactive subsidiaries stay as they were. */
    public function suspend(PlatformAdmin $actor, Company $root, string $reason): void
    {
        DB::transaction(function () use ($actor, $root, $reason): void {
            [$head, $children] = $this->lock($root);

            $this->refuseUnless($head->is_active, 'Perusahaan ini sudah ditangguhkan.');

            $affected = $children->where('is_active', true)->prepend($head);
            $now = now();

            foreach ($affected as $company) {
                $company->forceFill(['is_active' => false, 'suspended_at' => $now])->save();
            }

            $this->record($actor, 'tenant.suspended', $head, $affected, $reason, ['code' => $head->code]);
        });
    }

    /**
     * Reactivates the head and the subsidiaries the platform suspended (the ones with a suspension timestamp: a
     * subsidiary that was inactive before has none and stays so), and withdraws a deletion request.
     */
    public function reactivate(PlatformAdmin $actor, Company $root, string $reason): void
    {
        DB::transaction(function () use ($actor, $root, $reason): void {
            [$head, $children] = $this->lock($root);

            $this->refuseUnless(! $head->is_active, 'Perusahaan ini sudah aktif.');

            $affected = $children->whereNotNull('suspended_at')->prepend($head);
            $deletionRequested = $head->deletion_requested_at !== null;

            foreach ($affected as $company) {
                $company->forceFill(['is_active' => true, 'suspended_at' => null, 'deletion_requested_at' => null])->save();
            }

            $this->record($actor, 'tenant.reactivated', $head, $affected, $reason, [
                'code' => $head->code,
                'deletion_request_cleared' => $deletionRequested,
            ]);
        });
    }

    /** Records the request to delete; nothing is removed here (the purge is a separate, interactive command). */
    public function requestDeletion(PlatformAdmin $actor, Company $root): void
    {
        DB::transaction(function () use ($actor, $root): void {
            [$head] = $this->lock($root);

            $blockers = $this->deletionBlockers($head);

            if ($blockers !== []) {
                throw ValidationException::withMessages(['lifecycle' => $blockers]);
            }

            $head->forceFill(['deletion_requested_at' => now()])->save();

            $this->audit->platform('tenant.deletion_requested', [
                'code' => $head->code,
                'export_id' => $this->proofExport($head)?->id,
            ], $head, $actor);
            $this->audit->support('tenant.deletion_requested', [], $head, $actor);
        });
    }

    /**
     * What the detail page offers.
     *
     * @return array{can_suspend: bool, can_reactivate: bool, can_request_deletion: bool, deletion_blockers: list<string>}
     */
    public function state(Company $root): array
    {
        $blockers = $this->deletionBlockers($root);

        return [
            'can_suspend' => $root->is_active,
            'can_reactivate' => ! $root->is_active,
            'can_request_deletion' => $blockers === [],
            'deletion_blockers' => $blockers,
        ];
    }

    /**
     * Why a deletion request cannot be made now, in the operator's language; empty when it can. The state of the
     * tenant comes first (an active or never-platform-suspended tenant, or a request already made, ends the
     * list); otherwise every unmet condition is listed: thirty days suspended, and an export that finished after the
     * suspension (an `expired` one counts: the archive is gone, the record of the handover is the proof).
     *
     * @return list<string>
     */
    public function deletionBlockers(Company $root): array
    {
        if ($root->deletion_requested_at !== null) {
            return ['Pengajuan hapus sudah dibuat pada '.$this->at($root->deletion_requested_at).'.'];
        }

        if ($root->is_active || $root->suspended_at === null) {
            return ['Perusahaan belum ditangguhkan.'];
        }

        $blockers = [];
        $allowedFrom = $root->suspended_at->copy()->addDays(self::DELETION_AFTER_DAYS);

        if ($allowedFrom->isFuture()) {
            $blockers[] = 'Pengajuan hapus baru boleh setelah '.self::DELETION_AFTER_DAYS.' hari ditangguhkan (mulai '.$this->at($allowedFrom).').';
        }

        if ($this->proofExport($root) === null) {
            $blockers[] = 'Belum ada ekspor data yang selesai setelah penangguhan.';
        }

        return $blockers;
    }

    private function proofExport(Company $root): ?TenantExport
    {
        if ($root->suspended_at === null) {
            return null;
        }

        return TenantExport::query()
            ->where('company_id', $root->id)
            ->completedSince($root->suspended_at)
            ->orderByDesc('completed_at')
            ->first();
    }

    /**
     * The head and its subsidiaries, locked: head first, then the subsidiaries by id, always in that order.
     *
     * @return array{0: Company, 1: Collection<int, Company>}
     */
    private function lock(Company $root): array
    {
        $head = Company::query()->lockForUpdate()->findOrFail($root->id);
        $children = Company::query()->where('parent_company_id', $head->id)->orderBy('id')->lockForUpdate()->get();

        return [$head, $children];
    }

    private function refuseUnless(bool $allowed, string $message): void
    {
        if (! $allowed) {
            throw ValidationException::withMessages(['lifecycle' => $message]);
        }
    }

    /**
     * One platform row with every property, one support row per affected company with the reason alone.
     *
     * @param  Collection<int, Company>  $affected  the head first
     * @param  array<string, mixed>  $extra  more properties of the platform row
     */
    private function record(PlatformAdmin $actor, string $action, Company $head, Collection $affected, string $reason, array $extra): void
    {
        $this->audit->platform($action, ['reason' => $reason, ...$extra, 'affected_company_ids' => $affected->pluck('id')->all()], $head, $actor);

        foreach ($affected as $company) {
            $this->audit->support($action, ['reason' => $reason], $company, $actor);
        }
    }

    private function at(Carbon $moment): string
    {
        return $moment->copy()->utc()->locale('id')->translatedFormat('d M Y H:i').' UTC';
    }
}
