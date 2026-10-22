<?php

namespace App\Services\Platform;

use App\Models\PlatformAdmin;
use App\Services\Core\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\LogBatch;

/**
 * The audit trail of the operator plane, on top of AuditService (same `activity_log` table).
 *
 * - `platform` rows are internal: log name `platform`, hidden from every tenant reader. They name the platform
 *   admin as the causer and, when the event concerns a tenant, carry that tenant's company id from the subject.
 *   An event without a tenant subject keeps a null company.
 * - `support` rows are the part a tenant may see ("Akses dukungan oleh tim Inbils"): log name `support`, always
 *   about a tenant subject. Properties carry reasons, modes, durations and ids; never a platform admin's email, IP
 *   address or user agent, a secret, a token, a request body or a stack trace.
 *
 * Over HTTP the causer is the platform guard's admin. A login or a queued job has no such session, so it passes
 * the admin it acts for; a null causer there means the system.
 *
 * An action that tells the tenant too writes a `platform` row and then `support` rows. The `support` rows join the
 * `batch_uuid` of the `platform` row written just before for the same action, so the operator's audit page can show
 * the action once (PlatformAuditQuery): a `support` row with a batch is the tenant's copy of a `platform` row.
 */
class PlatformAuditService
{
    public const PLATFORM_LOG = 'platform';

    public const SUPPORT_LOG = 'support';

    /** @var array<string, string> action => batch of the `platform` row this instance wrote last for it */
    private array $batches = [];

    /**
     * @param  array<string, mixed>  $properties
     * @return ActivityContract|null null only when activity logging is switched off for the whole application
     */
    public function platform(string $action, array $properties = [], ?Model $subject = null, ?PlatformAdmin $causer = null): ?ActivityContract
    {
        return $this->write(self::PLATFORM_LOG, $action, $properties, $subject, $causer, $this->batches[$action] = (string) Str::uuid());
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  Model  $tenantSubject  the server-authoritative Company, User or TenantExport the event is about
     * @return ActivityContract|null null only when activity logging is switched off for the whole application
     */
    public function support(string $action, array $properties, Model $tenantSubject, ?PlatformAdmin $causer = null): ?ActivityContract
    {
        return $this->write(self::SUPPORT_LOG, $action, $properties, $tenantSubject, $causer, $this->batches[$action] ?? null);
    }

    /** @param  array<string, mixed>  $properties */
    private function write(string $log, string $action, array $properties, ?Model $subject, ?PlatformAdmin $causer, ?string $batch): ?ActivityContract
    {
        $open = app(LogBatch::class);

        // A batch that is already open (a support session's, see ImpersonationService) ties the row to its session.
        if ($batch === null || $open->isOpen()) {
            return AuditService::log($log, $action, $properties, $subject, causer: $causer);
        }

        $open->setBatch($batch);

        try {
            return AuditService::log($log, $action, $properties, $subject, causer: $causer);
        } finally {
            $open->endBatch();
        }
    }
}
