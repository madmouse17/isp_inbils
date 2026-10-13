import { useMemo } from 'react';
import { Badge, SearchSelect, Textarea, type SearchSelectOption } from '@/Components/ui';
import type { AcquirerOption, CustomerAcquirer } from '@/types/models';

interface CustomerAcquirerValueProps {
    acquirer?: CustomerAcquirer | null;
}

/** Acquirer name with a "Nonaktif" marker, or a muted "Belum ditentukan". Text carries the state. */
export function CustomerAcquirerValue({ acquirer }: CustomerAcquirerValueProps) {
    if (!acquirer) {
        return <span className="text-muted-foreground">Belum ditentukan</span>;
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <span className="font-medium">{acquirer.name}</span>
            {!acquirer.is_active ? <Badge variant="warning">Nonaktif</Badge> : null}
        </span>
    );
}

interface CustomerAcquirerFieldProps {
    /** Eligible acquirers from the server; the only selectable choices. */
    options: AcquirerOption[];
    /** Selected acquirer id as a string, `''` = none. */
    value: string;
    onChange: (value: string) => void;
    error?: string;
    hint?: string;
    disabled?: boolean;
    /** Stored acquirer, used for the read-only display and the historical read-out. */
    current?: CustomerAcquirer | null;
    /** Render the stored acquirer as plain text, with no control. */
    readOnly?: boolean;
    /** Show the correction reason textarea (a pending change/clear of a stored acquirer). */
    requireReason?: boolean;
    reason?: string;
    onReasonChange?: (value: string) => void;
    reasonError?: string;
}

export function CustomerAcquirerField({
    options,
    value,
    onChange,
    error,
    hint,
    disabled = false,
    current = null,
    readOnly = false,
    requireReason = false,
    reason = '',
    onReasonChange,
    reasonError,
}: CustomerAcquirerFieldProps) {
    const selectOptions: SearchSelectOption[] = useMemo(
        () =>
            options.map((option) => ({
                value: String(option.id),
                label: option.name,
                description: option.employee_number ?? undefined,
            })),
        [options],
    );

    if (readOnly) {
        return (
            <div className="space-y-1.5">
                <p className="text-sm leading-none font-medium">Diperoleh oleh</p>
                <div className="text-sm" data-testid="customer-acquirer-readonly">
                    <CustomerAcquirerValue acquirer={current} />
                </div>
            </div>
        );
    }

    const isHistorical = current !== null && !options.some((option) => option.id === current.id);

    return (
        <div className="space-y-3">
            <SearchSelect
                label="Diperoleh oleh"
                value={value}
                onChange={onChange}
                options={selectOptions}
                placeholder="Belum ditentukan, cari karyawan"
                emptyText="Tidak ada karyawan yang memenuhi syarat."
                error={error}
                hint={hint}
                disabled={disabled}
            />
            {isHistorical && current ? (
                <div className="space-y-1 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <span>
                            Saat ini diperoleh oleh:{' '}
                            <span className="font-medium">{current.name}</span>
                        </span>
                        {!current.is_active ? <Badge variant="warning">Nonaktif</Badge> : null}
                    </div>
                    <p className="text-muted-foreground">
                        Tidak bisa dipilih. Pilih karyawan yang memenuhi syarat atau kosongkan isian
                        ini untuk mengoreksi.
                    </p>
                </div>
            ) : null}
            {requireReason ? (
                <Textarea
                    label="Alasan koreksi"
                    value={reason}
                    onChange={(event) => onReasonChange?.(event.target.value)}
                    error={reasonError}
                    hint="Dicatat di log audit bersama nilai lama dan barunya."
                    disabled={disabled}
                    maxLength={500}
                    rows={3}
                    required
                />
            ) : null}
        </div>
    );
}
