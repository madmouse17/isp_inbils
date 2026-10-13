import { Input } from '@/Components/ui';

export interface CodeSequence {
    /** True when the entity accepts a hand-typed code; false when the system always generates it. */
    manual: boolean;
    /** Next code the engine would produce. A guess: a parallel create may take that number. */
    preview: string;
}

interface SequenceCodeFieldProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    sequence: CodeSequence;
    mode: 'create' | 'edit';
    id?: string;
    /** Viewer permission: the field is read-only even when manual input is allowed. */
    readOnly?: boolean;
}

/** Locked forms must not submit this field (strip it in `transform`); the server ignores or rejects it. */
export function SequenceCodeField({
    label,
    value,
    onChange,
    error,
    sequence,
    mode,
    id,
    readOnly = false,
}: SequenceCodeFieldProps) {
    if (!sequence.manual) {
        const creating = mode === 'create';
        return (
            <Input
                id={id}
                label={label}
                value={creating ? sequence.preview : value}
                readOnly
                aria-readonly="true"
                error={error}
                hint={
                    creating
                        ? `Dibuat otomatis saat disimpan, perkiraan: ${sequence.preview}`
                        : 'Dibuat otomatis dan tidak dapat diubah.'
                }
                className="bg-muted/50 font-mono text-muted-foreground"
            />
        );
    }

    return (
        <Input
            id={id}
            label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            error={error}
            readOnly={readOnly}
            required={mode === 'edit'}
            placeholder={sequence.preview}
            hint={mode === 'create' ? 'Kosongkan untuk dibuat otomatis' : undefined}
            className="font-mono"
        />
    );
}
