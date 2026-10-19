<?php

namespace Modules\SPK\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\SPK\Models\WorkOrder;

class StoreWorkOrderEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workOrder = $this->route('wo');

        return $workOrder instanceof WorkOrder
            && ($this->user()?->can('uploadEvidence', $workOrder) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf,doc,docx', 'mimes:jpg,jpeg,png,pdf,doc,docx', 'mimetypes:image/jpeg,image/png,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'caption' => ['nullable', 'string', 'max:255'],
            'captured_at' => ['nullable', Rule::date()->betweenOrEqual(now()->subDays(30)->toDateTimeString(), now()->addMinutes(5)->toDateTimeString())],
        ];
    }

    /** @return array{file: UploadedFile, caption?: ?string, captured_at?: ?string} */
    public function evidenceAttributes(): array
    {
        $data = $this->validated();
        $capturedAt = $data['captured_at'] ?? null;

        if (is_string($capturedAt)) {
            $data['captured_at'] = Carbon::parse($capturedAt)->toIso8601String();
        }

        return $data;
    }

    /** The same file with the same caption on one SPK is one piece of evidence: a second click, or a retry, finds the first. */
    public function idempotencyKey(): string
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return hash('sha256', hash_file('sha256', $file->getRealPath())."\n".$this->validated('caption'));
    }
}
