<?php

namespace App\Http\Requests\Concerns;

use App\Rules\PublicIdExists;
use App\Support\PublicId;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * The request boundary of the public ids (spec .scratch/opaque-ids, D3): a client names every record by its
 * `public_id`; before validation each declared field is replaced by the integer id of that record, so the rules
 * (company scope, active, cross-field checks), the services and the queries behind the request keep integer keys.
 *
 * A value that names no record the current company can see (an integer, another string, a list, an unknown id,
 * another company's id) is left as sent and the field gets PublicIdExists on top of its own rules, so the request is
 * refused; an integer id can never pass as a record.
 *
 * Use it in a FormRequest and declare the fields:
 *
 *     protected function publicIdFields(): array
 *     {
 *         return ['customer_id' => Customer::class, 'items.*.product_id' => Product::class];
 *     }
 *
 * `*` stands for a list index. A model that depends on another field (a polymorphic reference) is a closure over
 * the input that returns the model class, or null when the type is unknown (the type rule refuses it then).
 *
 * @phpstan-require-extends FormRequest
 */
trait ResolvesPublicIds
{
    /** @var array<string, array{0: class-string<Model>, 1: string}> path => [model, the pattern the request declared it by] */
    private array $unresolvedPublicIds = [];

    /** @return array<string, class-string<Model>|Closure(array<string, mixed>): (class-string<Model>|null)> */
    abstract protected function publicIdFields(): array;

    public function validateResolved(): void
    {
        $this->resolvePublicIds();

        parent::validateResolved();
    }

    /**
     * A path that names no record fails first and alone (bail): the client sees the request's own `<field>.exists`
     * message when it has one, never the `integer` noise of the id it sent.
     *
     * @return array<string, mixed>
     */
    protected function validationRules(): array
    {
        $rules = parent::validationRules();
        $messages = $this->messages();

        foreach ($this->unresolvedPublicIds as $path => [$model, $pattern]) {
            // A field the request leaves unvalidated never reaches validated(): there is nothing to refuse.
            if (! array_key_exists($path, $rules) && ! array_key_exists($pattern, $rules)) {
                continue;
            }

            $message = $messages[$path.'.exists'] ?? $messages[$pattern.'.exists'] ?? null;
            $rules[$path] = ['bail', new PublicIdExists($model, null, is_string($message) ? $message : null)];
        }

        return $rules;
    }

    private function resolvePublicIds(): void
    {
        $input = $this->input();
        $flat = Arr::dot($input);
        $changed = [];

        foreach ($this->publicIdFields() as $pattern => $model) {
            $model = $model instanceof Closure ? $model($input) : $model;

            if ($model === null) {
                continue;
            }

            $regex = $this->publicIdPattern($pattern);
            $paths = [];

            foreach (array_map('strval', array_keys($flat)) as $path) {
                if (preg_match($regex, $path, $match) !== 1) {
                    continue;
                }

                if ($match[1] === $path) {
                    $paths[] = $path;
                } elseif (is_array(Arr::get($input, $match[1]))) {
                    // `customer_id[]=5` flattens below the declared path, where nothing names it, and an exists rule
                    // alone accepts a list of existing ids: refuse the list like any value that names no record.
                    $this->unresolvedPublicIds[$match[1]] = [$model, $pattern];
                }
            }

            $ids = PublicId::resolve($model, array_map(fn (string $path): mixed => $flat[$path], $paths));

            foreach ($paths as $path) {
                $value = $flat[$path];

                if (is_string($value) && isset($ids[strtolower($value)])) {
                    data_set($input, $path, $ids[strtolower($value)]);
                    $changed[explode('.', $path)[0]] = true;
                } elseif ($value !== null && $value !== '') {
                    $this->unresolvedPublicIds[$path] = [$model, $pattern];
                }
            }
        }

        if ($changed !== []) {
            $this->merge(Arr::only($input, array_keys($changed)));
        }
    }

    /** Group 1 is the declared field; the key may continue below it after a dot (a list sent where one id belongs). */
    private function publicIdPattern(string $path): string
    {
        return '/^('.str_replace('\*', '[^.]+', preg_quote($path, '/')).')(?:\.|$)/';
    }
}
