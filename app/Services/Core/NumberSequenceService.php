<?php

namespace App\Services\Core;

use App\Models\Core\NumberSequence;
use Illuminate\Support\Facades\DB;

class NumberSequenceService
{
    public static function generate(string $entityType, ?string $prefix = null, ?int $companyId = null): string
    {
        $companyId = $companyId ?? CompanyService::currentId();

        return DB::transaction(function () use ($entityType, $prefix, $companyId) {
            $seq = NumberSequence::forCompany($companyId)
                ->where('entity_type', $entityType)
                ->lockForUpdate()
                ->first();

            if (! $seq) {
                $seq = NumberSequence::query()->create([
                    'company_id' => $companyId,
                    'entity_type' => $entityType,
                    'prefix' => $prefix ?: strtoupper(substr($entityType, 0, 3)),
                    'next_number' => 1,
                    'padding' => 5,
                    'year_suffix' => true,
                ]);
            }

            $number = $seq->next_number;
            $seq->next_number = $number + 1;
            $seq->save();

            $code = $seq->prefix;
            if ($seq->year_suffix) {
                $code .= '-'.now()->year;
            }
            $code .= '-'.str_pad((string) $number, $seq->padding, '0', STR_PAD_LEFT);

            return $code;
        });
    }

    public static function peek(string $entityType, ?int $companyId = null): ?string
    {
        $companyId = $companyId ?? CompanyService::currentId();
        $seq = NumberSequence::forCompany($companyId)
            ->where('entity_type', $entityType)
            ->first();

        if (! $seq) {
            return null;
        }

        $code = $seq->prefix;
        if ($seq->year_suffix) {
            $code .= '-'.now()->year;
        }
        $code .= '-'.str_pad((string) $seq->next_number, $seq->padding, '0', STR_PAD_LEFT);

        return $code;
    }
}
