<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use App\Models\Core\NumberSequence;
use App\Support\NumberSequences\NumberSequenceDefinition;
use App\Support\NumberSequences\NumberSequenceRegistry;
use App\Support\NumberSequences\NumberTemplate;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Hands out the business codes (invoice, SPK, ticket, customer, ...). A company has one row per registered entity with
 * its prefix, template and counter. The row lock makes parallel creates take consecutive numbers, and a code that
 * already exists in the entity's own table is skipped, so typed or imported codes never collide.
 */
class NumberSequenceService
{
    /** Taken codes in a row that generate() skips before it gives up. */
    private const MAX_SKIPS = 1000;

    /** What the Penomoran page may change; the company, entity and counter bookkeeping never come from a request. */
    private const EDITABLE = ['prefix', 'template', 'next_number', 'reset_period', 'allow_manual'];

    /** Runs inside the caller's transaction when there is one, so a rolled-back create gives its number back. */
    public static function generate(string $entityType, ?int $companyId = null): string
    {
        $definition = self::registry()->get($entityType);
        $companyId = self::companyId($companyId);

        return DB::transaction(function () use ($definition, $companyId): string {
            $sequence = NumberSequence::forCompany($companyId)
                ->where('entity_type', $definition->entityType)
                ->lockForUpdate()
                ->first() ?? self::createRow($definition, $companyId);

            [$code, $number, $periodKey] = self::next($sequence, $definition);

            $sequence->update(['next_number' => min($number + 1, NumberSequence::MAX_NUMBER), 'period_key' => $periodKey]);

            return $code;
        });
    }

    /** The code generate() would hand out now. Writes nothing, so a parallel create may still take it. */
    public static function peek(string $entityType, ?int $companyId = null): string
    {
        $definition = self::registry()->get($entityType);
        $companyId = self::companyId($companyId);

        return self::previewFor(
            NumberSequence::forCompany($companyId)->where('entity_type', $definition->entityType)->first()
                ?? self::newRow($definition, $companyId)
        );
    }

    /** peek() for a row already in hand; the Penomoran page lists nine of them with their company loaded once. */
    public static function previewFor(NumberSequence $sequence): string
    {
        try {
            return self::next($sequence, self::registry()->get($sequence->entity_type))[0];
        } catch (RuntimeException) {
            // A block of taken codes sits at the counter. Show where it stands so the page can still move it.
            [$companyCode, $now] = self::clock($sequence->company);

            return NumberTemplate::render($sequence->template, $sequence->prefix, $companyCode, $sequence->next_number, $now);
        }
    }

    public static function allowsManual(string $entityType, ?int $companyId = null): bool
    {
        $definition = self::registry()->get($entityType);
        $stored = NumberSequence::forCompany(self::companyId($companyId))
            ->where('entity_type', $definition->entityType)
            ->value('allow_manual');

        return $stored === null ? $definition->defaultAllowManual : (bool) $stored;
    }

    /** A typed code when the entity allows one, else a generated code. A typed code while manual input is off is refused. */
    public static function resolve(string $entityType, ?string $requested, string $field = 'code', ?int $companyId = null): string
    {
        $requested = trim((string) $requested);

        if ($requested === '') {
            return self::generate($entityType, $companyId);
        }

        if (! self::allowsManual($entityType, $companyId)) {
            throw ValidationException::withMessages([$field => 'Kolom ini dibuat otomatis oleh sistem dan tidak dapat diisi manual.']);
        }

        return $requested;
    }

    /** Creates the rows of every registered entity the company does not have yet. */
    public static function ensureAll(int $companyId): void
    {
        $existing = NumberSequence::forCompany($companyId)->pluck('entity_type')->all();

        foreach (self::registry()->all() as $definition) {
            if (! in_array($definition->entityType, $existing, true)) {
                self::createRow($definition, $companyId);
            }
        }
    }

    /**
     * Saves an edit of the Penomoran page. Existing codes never change. A new template or reset period starts counting
     * in the period it was saved in.
     *
     * @param  array{prefix: string, template: string, next_number: int, reset_period: string, allow_manual: bool}  $data
     */
    public static function update(NumberSequence $sequence, array $data): NumberSequence
    {
        return DB::transaction(function () use ($sequence, $data): NumberSequence {
            $sequence = NumberSequence::forCompany((int) $sequence->company_id)->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
            $sequence->fill(Arr::only($data, self::EDITABLE));

            if ($sequence->isDirty(['template', 'reset_period'])) {
                $sequence->period_key = $sequence->reset_period->key(self::clock($sequence->company)[1]);
            }

            $changed = array_keys($sequence->getDirty());

            if ($changed !== []) {
                $old = Arr::only($sequence->getRawOriginal(), $changed);
                $sequence->save();
                AuditService::log('number_sequence', 'updated', [
                    'old' => $old,
                    'new' => Arr::only($sequence->getAttributes(), $changed),
                ], $sequence);
            }

            return $sequence;
        });
    }

    /**
     * The code to hand out, the number inside it and the period key to store. A stored key that differs from the
     * current one means a new period, so the counter restarts at 1; a row that never stored a key joins the running one.
     *
     * @return array{0: string, 1: int, 2: string|null}
     */
    private static function next(NumberSequence $sequence, NumberSequenceDefinition $definition): array
    {
        [$companyCode, $now] = self::clock($sequence->company);
        $key = $sequence->reset_period->key($now);
        $number = $key !== null && $sequence->period_key !== null && $sequence->period_key !== $key ? 1 : $sequence->next_number;
        $skipped = 0;

        while (true) {
            $code = NumberTemplate::render($sequence->template, $sequence->prefix, $companyCode, $number, $now);

            if (mb_strlen($code) > $definition->maxLength) {
                throw new RuntimeException(sprintf(
                    'Penomoran %s: kode "%s" melebihi %d karakter. Ubah format di Administrasi > Penomoran.',
                    $definition->label,
                    $code,
                    $definition->maxLength,
                ));
            }

            if (! self::taken($definition, (int) $sequence->company_id, $code)) {
                return [$code, $number, $key];
            }

            if (++$skipped > self::MAX_SKIPS) {
                throw new RuntimeException(sprintf(
                    'Penomoran %s: %d kode berturut-turut sudah dipakai. Naikkan "Nomor berikutnya" di Administrasi > Penomoran.',
                    $definition->label,
                    self::MAX_SKIPS,
                ));
            }

            $number++;
        }
    }

    /** Soft-deleted rows count: their codes stay reserved. */
    private static function taken(NumberSequenceDefinition $definition, int $companyId, string $code): bool
    {
        return DB::table($definition->table)
            ->where('company_id', $companyId)
            ->where($definition->column, $code)
            ->exists();
    }

    private static function createRow(NumberSequenceDefinition $definition, int $companyId): NumberSequence
    {
        $sequence = self::newRow($definition, $companyId);

        try {
            $sequence->save();

            return $sequence;
        } catch (UniqueConstraintViolationException) {
            // A parallel request created the row first. Take the lock on theirs.
            return NumberSequence::forCompany($companyId)
                ->where('entity_type', $definition->entityType)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    /** An unsaved row with the definition's defaults that starts after the highest code already in the table. */
    private static function newRow(NumberSequenceDefinition $definition, int $companyId): NumberSequence
    {
        $company = Company::query()->find($companyId, ['id', 'code', 'timezone']);
        [$companyCode, $now] = self::clock($company);

        return (new NumberSequence([
            'company_id' => $companyId,
            'entity_type' => $definition->entityType,
            'prefix' => $definition->defaultPrefix,
            'template' => $definition->defaultTemplate,
            'next_number' => self::startingNumber($definition, $companyId, $companyCode, $now),
            'reset_period' => $definition->defaultReset,
            'period_key' => $definition->defaultReset->key($now),
            'allow_manual' => $definition->defaultAllowManual,
        ]))->setRelation('company', $company);
    }

    /** 1 + the highest number among the codes in the current period that have the default format. Other codes are left alone. */
    private static function startingNumber(NumberSequenceDefinition $definition, int $companyId, string $companyCode, CarbonInterface $now): int
    {
        $highest = 0;

        // shortcut: reads every code of the company once, when its row is created; page it with lazyById past a few hundred thousand rows.
        foreach (DB::table($definition->table)->where('company_id', $companyId)->pluck($definition->column) as $existing) {
            $number = NumberTemplate::parse($definition->defaultTemplate, (string) $existing, $definition->defaultPrefix, $companyCode, $definition->defaultReset, $now);
            $highest = max($highest, $number ?? 0);
        }

        return min($highest + 1, NumberSequence::MAX_NUMBER);
    }

    /**
     * The company's code and the current time in its timezone: date tokens and period keys follow the company's day.
     *
     * @return array{0: string, 1: CarbonInterface}
     */
    private static function clock(?Company $company): array
    {
        return [(string) $company?->code, now($company?->timezone ?: (string) config('app.timezone'))];
    }

    private static function companyId(?int $companyId): int
    {
        return $companyId ?? CompanyService::currentId() ?? abort(403, 'Company context is required.');
    }

    private static function registry(): NumberSequenceRegistry
    {
        return app(NumberSequenceRegistry::class);
    }
}
