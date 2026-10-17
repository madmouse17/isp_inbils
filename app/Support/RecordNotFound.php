<?php

namespace App\Support;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one answer for a record the caller must not be able to tell apart: it does not exist, it belongs to another
 * company, or the caller's role cannot see it (spec .scratch/opaque-ids, K3). A route-model binding miss,
 * `findOrFail()`, this exception and a policy's `Response::denyAsNotFound()` all render the same plain 404, and
 * DetectRecordEnumeration counts every one of them. A missing permission is not a hidden record: that stays 403.
 *
 *     $row = Row::query()->wherePublicId($id)->first() ?? RecordNotFound::abort();
 *     RecordNotFound::abortUnless($contact->customer_id === $customer->id);
 */
final class RecordNotFound extends NotFoundHttpException
{
    public static function abort(): never
    {
        throw new self();
    }

    public static function abortIf(mixed $condition): void
    {
        if ($condition) {
            self::abort();
        }
    }

    public static function abortUnless(mixed $condition): void
    {
        if (! $condition) {
            self::abort();
        }
    }
}
