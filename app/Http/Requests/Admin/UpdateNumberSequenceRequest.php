<?php

namespace App\Http\Requests\Admin;

use App\Models\Core\NumberSequence;
use App\Support\NumberSequences\NumberSequenceRegistry;
use App\Support\NumberSequences\NumberTemplate;
use App\Support\NumberSequences\ResetPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateNumberSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $sequence = $this->route('number_sequence');

        return $sequence instanceof NumberSequence && $this->user()?->can('update', $sequence) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // \z, not $: a trailing newline must not slip into a code.
            'prefix' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,20}\z/'],
            'template' => ['required', 'string', 'max:100'],
            'next_number' => ['required', 'integer', 'min:1', 'max:'.NumberSequence::MAX_NUMBER],
            'reset_period' => ['required', Rule::in(array_column(ResetPeriod::cases(), 'value'))],
            'allow_manual' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'prefix.required' => 'Awalan wajib diisi.',
            'prefix.regex' => 'Awalan hanya boleh berisi huruf, angka, - dan _ (maksimal 20 karakter).',
            'template.required' => 'Format wajib diisi.',
            'template.max' => 'Format maksimal 100 karakter.',
            'next_number.required' => 'Nomor berikutnya wajib diisi.',
            'next_number.integer' => 'Nomor berikutnya harus berupa angka bulat.',
            'next_number.min' => 'Nomor berikutnya minimal 1.',
            'next_number.max' => 'Nomor berikutnya terlalu besar.',
            'reset_period.required' => 'Reset penomoran wajib dipilih.',
            'reset_period.in' => 'Pilihan reset penomoran tidak valid.',
            'allow_manual.required' => 'Pilihan input manual wajib diisi.',
            'allow_manual.boolean' => 'Pilihan input manual tidak valid.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(array_keys($this->rules()))) {
                return;
            }

            /** @var NumberSequence $sequence */
            $sequence = $this->route('number_sequence');
            $definition = app(NumberSequenceRegistry::class)->all()[$sequence->entity_type] ?? abort(404);
            $template = (string) $this->input('template');

            $problem = NumberTemplate::validate($template, ResetPeriod::from((string) $this->input('reset_period')));

            if ($problem !== null) {
                $validator->errors()->add('template', $problem);

                return;
            }

            $width = (int) NumberTemplate::seqWidth($template);
            $largest = 10 ** $width - 1;

            if ((int) $this->input('next_number') > min($largest, NumberSequence::MAX_NUMBER)) {
                $validator->errors()->add('next_number', 'Nomor berikutnya maksimal '.min($largest, NumberSequence::MAX_NUMBER).' untuk {SEQ:'.$width.'}.');
            }

            $longest = NumberTemplate::render($template, (string) $this->input('prefix'), (string) $sequence->company?->code, $largest, now());

            if (mb_strlen($longest) > $definition->maxLength) {
                $validator->errors()->add('template', 'Format menghasilkan kode lebih dari '.$definition->maxLength.' karakter.');
            }
        }];
    }
}
