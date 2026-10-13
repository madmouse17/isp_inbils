<?php

namespace Modules\Service\Services;

use Modules\Ticketing\Models\Ticket;

/**
 * Measured results per SLA tier for the tier index: tickets created in the last 30 days on subscriptions
 * whose package uses the tier. Company scope comes from the Ticket global scope. A rate counts only the
 * tickets that carry the matching target and the matching timestamp (resolved / first response); no such
 * ticket gives null, so old tickets without a response target never count.
 */
final class SlaTierCompliance
{
    public const WINDOW_DAYS = 30;

    /**
     * @param  list<int>  $tierIds
     * @return array<int, array{tickets_30d: int, resolution_on_time_rate_30d: ?float, response_on_time_rate_30d: ?float}>
     */
    public function forTiers(array $tierIds): array
    {
        if ($tierIds === []) {
            return [];
        }

        $rows = Ticket::query()
            ->join('service_subscriptions', 'service_subscriptions.id', '=', 'tickets.subscription_id')
            ->join('service_packages', 'service_packages.id', '=', 'service_subscriptions.service_package_id')
            ->whereIn('service_packages.sla_tier_id', $tierIds)
            ->where('tickets.created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->groupBy('service_packages.sla_tier_id')
            ->selectRaw(<<<'SQL'
                service_packages.sla_tier_id AS tier_id,
                COUNT(*) AS tickets,
                COALESCE(SUM(tickets.resolved_at IS NOT NULL AND tickets.sla_deadline IS NOT NULL), 0) AS resolution_measured,
                COALESCE(SUM(tickets.resolved_at <= tickets.sla_deadline), 0) AS resolution_on_time,
                COALESCE(SUM(tickets.first_response_at IS NOT NULL AND tickets.response_deadline IS NOT NULL), 0) AS response_measured,
                COALESCE(SUM(tickets.first_response_at <= tickets.response_deadline), 0) AS response_on_time
                SQL)
            ->get()
            ->keyBy('tier_id');

        $result = [];
        foreach ($tierIds as $id) {
            $row = $rows->get($id);
            $result[$id] = [
                'tickets_30d' => (int) ($row->tickets ?? 0),
                'resolution_on_time_rate_30d' => self::rate($row->resolution_on_time ?? 0, $row->resolution_measured ?? 0),
                'response_on_time_rate_30d' => self::rate($row->response_on_time ?? 0, $row->response_measured ?? 0),
            ];
        }

        return $result;
    }

    private static function rate(int|string $onTime, int|string $measured): ?float
    {
        return (int) $measured === 0 ? null : round((int) $onTime / (int) $measured * 100, 1);
    }
}
