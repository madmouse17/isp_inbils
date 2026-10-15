<?php

namespace Modules\NetworkAsset\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Serialises one company's changes to the fibre graph (PON ports, splitters, connections). The lock is taken
 * before the transaction opens and released after it commits, so the next holder's transaction starts on
 * committed rows. It is not the only guard: the cycle walk reads splitters with a shared lock, and the
 * transaction is retried when two writers still deadlock (a lock store that is not shared between processes).
 */
final class OdnLock
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(int $companyId, Closure $callback): mixed
    {
        try {
            return Cache::lock("odn:topology:{$companyId}", 10)->block(5, fn () => DB::transaction($callback, 3));
        } catch (LockTimeoutException) {
            abort(409, 'Ada perubahan lain pada rencana fiber yang sedang berjalan. Coba lagi.');
        }
    }
}
