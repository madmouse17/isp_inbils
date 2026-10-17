<?php

namespace App\Rules;

use App\Support\PublicId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The value is the public id (ULID) of a record the current company can see: an integer, any other string, an
 * unknown id and the id of another company's record all fail with the same message. A company model answers for
 * the current company through its own global scope; `$constrain` adds what the field requires on top.
 *
 * @param  class-string<Model>  $model
 * @param  (Closure(Builder<Model>): mixed)|null  $constrain
 */
final class PublicIdExists implements ValidationRule
{
    public function __construct(
        private readonly string $model,
        private readonly ?Closure $constrain = null,
        private readonly ?string $message = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (PublicId::resolveOne($this->model, $value, $this->constrain) === null) {
            $this->message === null ? $fail('validation.exists')->translate() : $fail($this->message);
        }
    }
}
