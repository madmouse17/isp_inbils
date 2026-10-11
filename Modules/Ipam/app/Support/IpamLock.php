<?php

namespace Modules\Ipam\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Serialises one company's read-modify-write on the address plan. The lock is taken before the transaction
 * opens and released after it commits, so the next holder always reads committed rows.
 */
final class IpamLock
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(string $scope, int $companyId, Closure $callback): mixed
    {
        try {
            return Cache::lock("ipam:{$scope}:{$companyId}", 10)->block(5, fn () => DB::transaction($callback, 3));
        } catch (LockTimeoutException) {
            abort(409, 'Another change to the address plan is in progress. Try again.');
        }
    }
}
