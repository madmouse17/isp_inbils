<?php

namespace Database\Seeders\Demo;

use App\Models\Core\EmployeeEvaluation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

/** Managers and staff scoring finished jobs and resolved tickets, a few days after they were closed out. */
final class EvaluationDemo
{
    /** Range of scores each technician tends to get. */
    private const SCORES = ['technician' => [4.0, 5.0], 'tech_agus' => [4.5, 5.0], 'tech_dedi' => [3.5, 4.5], 'tech_yoga' => [3.0, 4.0]];

    private const COMMENTS = [
        'Pekerjaan rapi dan selesai tepat waktu.',
        'Komunikasi dengan pelanggan baik, jalur kabel tertata.',
        'Dokumentasi foto perlu dilengkapi dengan hasil pengukuran redaman.',
        'Penanganan cepat, pelanggan puas.',
        'Perlu lebih teliti saat memasang klem dan merapikan kabel.',
        null,
    ];

    public static function seed(DemoContext $c): void
    {
        $technicians = array_flip(array_map(fn (string $key) => $c->user($key)->id, array_keys(self::SCORES)));
        $technicianKeys = array_keys(self::SCORES);

        $jobs = WorkOrder::query()->where('status', 'completed')->whereIn('assigned_to', array_keys($technicians))->orderBy('completed_at')->get();

        foreach ($jobs as $job) {
            if (! $c->chance(65)) {
                continue;
            }

            self::evaluate($c, $technicianKeys[$technicians[$job->assigned_to]], 'WorkOrder', $job->id, CarbonImmutable::instance($job->completed_at), [
                'resolution_minutes' => $job->started_at !== null ? (int) abs($job->started_at->diffInMinutes($job->completed_at)) : null,
            ]);
        }

        $tickets = Ticket::query()->whereIn('status', ['resolved', 'closed'])->whereIn('assigned_to', array_keys($technicians))->orderBy('resolved_at')->get();

        foreach ($tickets as $ticket) {
            if (! $c->chance(55)) {
                continue;
            }

            self::evaluate($c, $technicianKeys[$technicians[$ticket->assigned_to]], 'Ticket', $ticket->id, CarbonImmutable::instance($ticket->resolved_at), [
                'first_response_minutes' => $ticket->first_response_at !== null ? (int) abs($ticket->created_at->diffInMinutes($ticket->first_response_at)) : null,
                'resolution_minutes' => (int) abs($ticket->created_at->diffInMinutes($ticket->resolved_at)),
            ]);
        }
    }

    /** @param array<string, int|null> $snapshot */
    private static function evaluate(DemoContext $c, string $technician, string $referenceType, int $referenceId, CarbonImmutable $finished, array $snapshot): void
    {
        $when = $c->clamp($finished->addDays($c->int(1, 3))->addHours($c->int(1, 6)));
        [$low, $high] = self::SCORES[$technician];
        $c->travel($when);

        $c->as($c->pick(['manager', 'manager', 'staff', 'admin']), fn (User $evaluator) => EmployeeEvaluation::query()->create([
            'employee_id' => $c->user($technician)->id,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'score' => round($c->int((int) ($low * 10), (int) ($high * 10)) / 10, 1),
            'customer_rating' => $c->chance(75) ? round($c->int((int) (($low - 0.5) * 10), 50) / 10, 1) : null,
            'comment' => $c->pick(self::COMMENTS),
            'evaluator_id' => $evaluator->id,
            'evaluated_at' => $when,
            ...$snapshot,
        ]));
    }
}
