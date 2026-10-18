<?php

namespace App\Services\Core;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Deactivate, activate, hard-delete and restore of the records that use HasLifecycle. A refusal is a
 * ValidationException on the `lifecycle` key, so a web form shows it in place and a JSON client gets a 422.
 */
final class LifecycleService
{
    /** MySQL ER_ROW_IS_REFERENCED_2: a child row points at the row being deleted. */
    private const FOREIGN_KEY_REFERENCED = 1451;

    public static function deactivate(Model $model): void
    {
        self::setActive($model, false, 'deactivated');
    }

    public static function activate(Model $model): void
    {
        self::setActive($model, true, 'activated');
    }

    /**
     * Hard delete, only while no row points at the record, trashed ones included. The row lock is the first
     * statement of the transaction, so the counts that follow see every child committed while the lock was awaited;
     * the foreign keys stay the backstop. Call it outside a transaction that already read the children.
     *
     * @param  (Closure(Model): void)|null  $beforeDelete  gets the locked row, inside the lock, after the zero-reference
     *                                                     check; a deadlock retry runs it again, so keep it to database writes
     */
    public static function destroyIfUnreferenced(Model $model, ?Closure $beforeDelete = null): void
    {
        if ($model::lifecycleReferences() === null) {
            throw new LogicException($model::class.' keeps its own delete rules.');
        }

        try {
            DB::transaction(function () use ($model, $beforeDelete): void {
                $locked = $model->newModelQuery()
                    ->whereKey($model->getKey())
                    ->whereNull($model->getQualifiedDeletedAtColumn())
                    ->lockForUpdate()
                    ->firstOrFail();

                $references = $locked->countReferences();

                if (array_sum(array_column($references, 'count')) > 0) {
                    throw self::refusal($locked, 'masih dipakai '.self::describe($references));
                }

                if ($beforeDelete !== null) {
                    $beforeDelete($locked);
                }

                $locked->forceDelete();
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== self::FOREIGN_KEY_REFERENCED) {
                throw $e;
            }

            throw self::refusal($model, 'baru saja dipakai data lain');
        }

        self::audit($model, 'force_deleted');
    }

    /**
     * Restoring a live row does nothing.
     *
     * @param  (Closure(Model): void)|null  $afterRestore  gets the restored row, inside the lock, after it is back
     */
    public static function restore(Model $model, ?Closure $afterRestore = null): void
    {
        $restored = DB::transaction(function () use ($model, $afterRestore): bool {
            $locked = $model->newModelQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->trashed()) {
                return false;
            }

            self::assertParentIsLive($locked);

            try {
                $locked->restore();
            } catch (UniqueConstraintViolationException) {
                throw self::restoreRefusal($locked, 'kode atau namanya sudah dipakai data lain. Ubah data itu dulu.');
            }

            if ($afterRestore !== null) {
                $afterRestore($locked);
            }

            return true;
        }, 3);

        if ($restored) {
            self::audit($model, 'restored', withSubject: true);
        }
    }

    /**
     * "3 paket, 12 langganan", skipping the zero counts.
     *
     * @param  list<array{label: string, count: int}>  $references
     */
    public static function describe(array $references): string
    {
        $parts = [];

        foreach ($references as $reference) {
            if ($reference['count'] > 0) {
                $parts[] = $reference['count'].' '.$reference['label'];
            }
        }

        return implode(', ', $parts);
    }

    private static function setActive(Model $model, bool $active, string $action): void
    {
        if (! $model::isDeactivatable()) {
            throw new LogicException($model::class.' has no active flag.');
        }

        if ((bool) $model->getAttribute('is_active') === $active) {
            return;
        }

        $model->forceFill(['is_active' => $active])->save();

        self::audit($model, $action, withSubject: true);
    }

    /** A row cannot come back under a parent that is still deleted. */
    private static function assertParentIsLive(Model $locked): void
    {
        $parentId = $locked->getAttribute('parent_id');

        if ($parentId === null) {
            return;
        }

        $parent = $locked->newModelQuery()->whereKey($parentId)->first();

        if ($parent?->trashed()) {
            throw self::restoreRefusal($locked, 'induknya, '.($parent->getAttribute('name') ?? $parent->getAttribute('code')).', masih dihapus. Pulihkan induknya dulu.');
        }
    }

    private static function refusal(Model $model, string $reason): ValidationException
    {
        $tail = $model::isDeactivatable() ? '. Nonaktifkan saja.' : ', jadi tidak bisa dihapus.';

        return ValidationException::withMessages(['lifecycle' => $model::lifecycleNoun().' '.$reason.$tail]);
    }

    private static function restoreRefusal(Model $model, string $reason): ValidationException
    {
        return ValidationException::withMessages(['lifecycle' => $model::lifecycleNoun().' tidak bisa dipulihkan karena '.$reason]);
    }

    private static function audit(Model $model, string $action, bool $withSubject = false): void
    {
        AuditService::log(Str::singular($model->getTable()), $action, [
            'id' => $model->getAttribute('public_id'),
            'name' => $model->getAttribute('name') ?? $model->getAttribute('code'),
        ], $withSubject ? $model : null, $model->getAttribute('company_id'));
    }
}
