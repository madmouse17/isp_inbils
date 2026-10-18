<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The last save must not win silently over a newer one: an edit form sends the version it was rendered from (FIELD)
 * and the write compares it with the row it holds under lockForUpdate. A mismatch is the shared 409
 * (bootstrap/app.php): the Inertia page gets errors.conflict and the toast, a JSON client {message, error: conflict}.
 *
 * Adopting an update: add FIELD => rules() to the FormRequest, lock the row inside the write's transaction, call
 * assert() on that locked row, and write $request->safe()->except(FIELD) so the version never reaches mass assignment.
 */
final class OptimisticLock
{
    public const FIELD = 'expected_updated_at';

    /** House format: SpkService, WorkOrderResource. */
    public const FORMAT = 'Y-m-d\TH:i:s.v\Z';

    public const MESSAGE = 'Data diubah pengguna lain; muat ulang.';

    /** @return list<string> FormRequest rule for FIELD */
    public static function rules(): array
    {
        return ['required', 'string', 'date_format:'.self::FORMAT];
    }

    /** The version a resource emits: updated_at in UTC, milliseconds. */
    public static function version(Model $model): ?string
    {
        return $model->getAttribute('updated_at')?->utc()->format(self::FORMAT);
    }

    /**
     * Call with the row read under lockForUpdate, before writing it.
     *
     * shortcut: timestamps() keep whole seconds, so two writes in one second share a version; add a lock_version
     * column if a machine writer ever edits these rows within a second of a person.
     *
     * @throws ConflictHttpException
     */
    public static function assert(Model $locked, string $expected): void
    {
        if (self::version($locked) !== $expected) {
            throw new ConflictHttpException(self::MESSAGE);
        }
    }
}
