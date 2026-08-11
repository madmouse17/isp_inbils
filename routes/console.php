<?php

use Illuminate\Support\Facades\Schedule;

// Both commands now enumerate all active companies internally when no
// --company option is passed, so a single schedule entry covers every tenant.
Schedule::command('billing:generate')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('billing:check-overdue')->dailyAt('03:00')->withoutOverlapping();

// SLA checks run per-company via CheckTicketSlaBreachesJob; the command
// iterates active companies and dispatches one job per tenant.
Schedule::command('tickets:sla')->everyFifteenMinutes()->withoutOverlapping();
