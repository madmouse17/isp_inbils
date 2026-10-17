<?php

use App\Models\Core\WhatsAppInboundReceipt;
use App\Models\Core\WhatsAppMessage;
use Illuminate\Support\Facades\Schedule;

// Both commands now enumerate all active companies internally when no
// --company option is passed, so a single schedule entry covers every tenant.
Schedule::command('billing:generate')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('billing:check-overdue')->dailyAt('03:00')->withoutOverlapping();

// Isolir runs in office hours so customer service can take the calls it causes; after the 03:00 overdue check.
Schedule::command('billing:suspend-overdue')->dailyAt('08:00')->withoutOverlapping();

// SLA checks run per-company via CheckTicketSlaBreachesJob; the command
// iterates active companies and dispatches one job per tenant.
Schedule::command('tickets:sla')->everyFifteenMinutes()->withoutOverlapping();

// Backstop for lost Midtrans notifications: asks Midtrans about attempts still pending after
// ten minutes, per active company. A no-op while no Midtrans server key is configured.
Schedule::command('midtrans:reconcile')->everyFifteenMinutes()->withoutOverlapping();

// Re-queues RADIUS accounts that are pending, owe a Disconnect, failed over an hour ago or drifted, and writes
// NAS client rows that are not in RADIUS yet. Does nothing while RADIUS_ENABLED is off.
Schedule::command('network:sync')->everyFifteenMinutes()->withoutOverlapping();

// Scheduler heartbeat — keeps the /ready scheduler probe green.
// Run every 5 minutes so the 300-second HEARTBEAT_MAX_AGE window is never missed.
Schedule::command('ops:scheduler-heartbeat')->everyFiveMinutes();

// WhatsApp payment reminders (H-3, H-1, H+1) for invoices that still owe money. 01:00 UTC is 08:00 in
// Jakarta, before the default quiet window (21:00-07:00) can matter; "today" is computed per company timezone.
Schedule::command('billing:whatsapp-reminders')->dailyAt('01:00')->withoutOverlapping();

Schedule::command('announcements:publish-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('promo-banners:cleanup-images')->daily()->withoutOverlapping();

Schedule::command('inbils:platform:exports:prune')->hourly()->withoutOverlapping();

// The WhatsApp message log keeps 12 months (WhatsAppMessage::prunable()), the STOP receipts 30 days (WhatsAppInboundReceipt::prunable()).
Schedule::command('model:prune', ['--model' => [WhatsAppMessage::class, WhatsAppInboundReceipt::class]])->daily();

// Mobile app tokens carry their own expires_at (90 days customer, 30 technician); this drops the ones that
// expired more than a day ago.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
