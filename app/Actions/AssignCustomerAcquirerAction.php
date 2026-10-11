<?php

namespace App\Actions;

use App\Models\Core\Customer;
use App\Models\User;
use App\Queries\Admin\CustomerAcquirerQuery;
use App\Services\Core\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The only writer of customers.acquired_by_user_id (the column is not mass assignable). Input is the
 * validated request subset: acquired_by_user_id and acquirer_correction_reason. Eligibility and the
 * reason requirement are validated at the request boundary; capabilities are enforced here through
 * CustomerPolicy. Every change writes an audit row with old, new, reason and actor.
 */
class AssignCustomerAcquirerAction
{
    /**
     * First attribution of a new customer. An explicit choice (null = Unassigned) counts only for
     * holders of customer.sales.assign; otherwise the creator is credited when eligible.
     *
     * @param  array<string, mixed>  $input
     */
    public static function onCreate(Customer $customer, User $creator, array $input): void
    {
        $explicit = array_key_exists('acquired_by_user_id', $input) && $creator->can('assignAcquirer', Customer::class);
        $acquirerId = $explicit ? self::toId($input['acquired_by_user_id']) : CustomerAcquirerQuery::defaultId($creator);

        if ($acquirerId !== null) {
            self::write($customer, $creator, $acquirerId, null);
        }
    }

    /**
     * Assigning an Unassigned customer needs customer.sales.assign; changing or clearing a set acquirer
     * is a correction and needs customer.sales.correct. Resubmitting the current value changes nothing.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     */
    public static function onUpdate(Customer $customer, User $actor, array $input): void
    {
        if (! array_key_exists('acquired_by_user_id', $input)) {
            return;
        }

        $new = self::toId($input['acquired_by_user_id']);

        if ($new === $customer->acquired_by_user_id) {
            return;
        }

        Gate::forUser($actor)->authorize($customer->acquired_by_user_id === null ? 'assignAcquirer' : 'correctAcquirer', $customer);

        self::write($customer, $actor, $new, self::toReason($input['acquirer_correction_reason'] ?? null));
    }

    private static function write(Customer $customer, User $actor, ?int $new, ?string $reason): void
    {
        DB::transaction(function () use ($customer, $actor, $new, $reason): void {
            $old = $customer->acquired_by_user_id;

            $customer->forceFill(['acquired_by_user_id' => $new])->save();

            AuditService::log('customer', $old === null ? 'acquirer_assigned' : 'acquirer_corrected', [
                'old' => $old,
                'new' => $new,
                'reason' => $reason,
                'actor_id' => $actor->id,
            ], $customer);
        });
    }

    private static function toId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! CustomerAcquirerQuery::isId($value)) {
            throw new InvalidArgumentException('The acquirer id must be an integer or a string of digits.');
        }

        return (int) $value;
    }

    private static function toReason(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
