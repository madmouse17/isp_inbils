<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Notifications\InventoryRequestApprovedNotification;
use Modules\Inventory\Notifications\InventoryRequestReadyNotification;
use Modules\Inventory\Notifications\InventoryRequestRejectedNotification;
use Modules\Inventory\Notifications\InventoryRequestSubmittedNotification;
use Modules\Inventory\Notifications\MaterialReturnDeclaredNotification;
use Modules\Inventory\Notifications\MaterialReturnExceptionNotification;
use Modules\Inventory\Notifications\MaterialReturnRejectedNotification;

/**
 * Ticket notifications come from the ticket flow itself. The warehouse notifications are mail-only in the
 * app, so the in-app inbox gets the same events written from the requests and cases that exist, and the
 * billing scheduler reports two failed runs to the administrator.
 */
final class NotificationDemo
{
    /** Notifications older than this many days have been read. */
    private const UNREAD_DAYS = 3;

    public static function seed(DemoContext $c): void
    {
        $companyId = $c->companyId();

        foreach ([[34, 'billing:generate'], [2, 'billing:check-overdue']] as [$days, $command]) {
            self::push($c->user('admin'), new BillingScheduleFailedNotification($command, $companyId), $c->day($days, 2, 5));
        }

        foreach (InventoryRequest::query()->get() as $request) {
            if ($request->status === 'submitted') {
                foreach (['manager', 'admin'] as $approver) {
                    self::push($c->user($approver), new InventoryRequestSubmittedNotification($request->id, $request->code, $companyId), CarbonImmutable::instance($request->submitted_at));
                }
            }

            $requester = User::query()->findOrFail($request->requested_by);

            if ($request->approved_at !== null) {
                self::push($requester, new InventoryRequestApprovedNotification($request->id, $request->code, $companyId), CarbonImmutable::instance($request->approved_at));
            }

            if ($request->ready_at !== null) {
                self::push($requester, new InventoryRequestReadyNotification($request->id, $request->code, $companyId), CarbonImmutable::instance($request->ready_at));
            }

            if ($request->status === 'rejected') {
                self::push($requester, new InventoryRequestRejectedNotification($request->id, $request->code, (string) $request->rejection_reason, $companyId), CarbonImmutable::instance($request->rejected_at));
            }
        }

        foreach (MaterialReturnCase::query()->get() as $case) {
            if ($case->status === 'awaiting_receipt') {
                foreach (['staff', 'manager', 'admin'] as $receiver) {
                    self::push($c->user($receiver), new MaterialReturnDeclaredNotification($case->id, $case->work_order_id, $companyId), CarbonImmutable::instance($case->declared_at));
                }
            }

            if ($case->status === 'exception_open') {
                foreach (['manager', 'admin'] as $resolver) {
                    self::push($c->user($resolver), new MaterialReturnExceptionNotification($case->id, $case->work_order_id, $companyId), CarbonImmutable::instance($case->received_at));
                }
            }

            if ($case->status === 'correction_required') {
                self::push(User::query()->findOrFail($case->technician_id), new MaterialReturnRejectedNotification($case->id, (string) $case->rejection_reason, $companyId), CarbonImmutable::instance($case->rejected_at));
            }
        }

        self::markOldAsRead($c);
    }

    /** The inbox row the `database` channel would have written for the notification. */
    private static function push(User $user, Notification $notification, CarbonImmutable $at): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $notification::class,
            'data' => method_exists($notification, 'toArray') ? $notification->toArray($user) : [],
            'read_at' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** Everything but the last few days is read, a few hours to a day after it arrived. The five demo logins keep their newest one unread. */
    private static function markOldAsRead(DemoContext $c): void
    {
        $cutoff = $c->now->subDays(self::UNREAD_DAYS);
        $inbox = fn () => DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', User::query()->where('company_id', $c->companyId())->select('id'));

        $inbox()
            ->whereNull('read_at')
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->get(['id', 'created_at'])
            ->each(fn (object $row) => DB::table('notifications')->where('id', $row->id)->update([
                'read_at' => CarbonImmutable::parse($row->created_at)->addMinutes($c->int(40, 1600)),
            ]));

        foreach (['admin', 'manager', 'staff', 'technician', 'customer'] as $login) {
            $userId = $c->user($login)->id;

            if ($inbox()->where('notifiable_id', $userId)->whereNull('read_at')->doesntExist()) {
                $inbox()->where('notifiable_id', $userId)->orderByDesc('created_at')->limit(1)->update(['read_at' => null]);
            }
        }
    }
}
