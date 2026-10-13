import type { FormEvent } from 'react';
import { useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Alert,
    AlertDescription,
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    Label,
    Modal,
    NativeSelect,
    Switch,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { renderTemplate, validateTemplate, type ResetPeriod } from '@/lib/numberTemplate';
import type { PageProps } from '@/types';
import type { NumberSequence, NumberSequenceToken } from '@/types/number-sequence';

interface IndexProps extends Record<string, unknown> {
    sequences: NumberSequence[];
    company_code: string;
    tokens: NumberSequenceToken[];
}

interface SequenceForm {
    prefix: string;
    template: string;
    next_number: string;
    reset_period: ResetPeriod;
    allow_manual: boolean;
}

const RESET_LABELS: Record<ResetPeriod, string> = {
    never: 'Tidak pernah',
    yearly: 'Setiap tahun',
    monthly: 'Setiap bulan',
    daily: 'Setiap hari',
};

const RESET_OPTIONS = (Object.keys(RESET_LABELS) as ResetPeriod[]).map((value) => ({
    value,
    label: RESET_LABELS[value],
}));

const formOf = (sequence: NumberSequence): SequenceForm => ({
    prefix: sequence.prefix,
    template: sequence.template,
    next_number: String(sequence.next_number),
    reset_period: sequence.reset_period,
    allow_manual: sequence.allow_manual,
});

export default function Index({ sequences, company_code, tokens }: IndexProps) {
    const timeZone = usePage<PageProps>().props.company?.timezone ?? 'UTC';
    const [editing, setEditing] = useState<NumberSequence | null>(null);
    const templateRef = useRef<HTMLInputElement>(null);
    const { data, setData, put, processing, errors, clearErrors, reset } = useForm<SequenceForm>({
        prefix: '',
        template: '',
        next_number: '1',
        reset_period: 'never',
        allow_manual: false,
    });

    const open = (sequence: NumberSequence) => {
        clearErrors();
        reset();
        setData(formOf(sequence));
        setEditing(sequence);
    };

    const close = () => setEditing(null);

    const insertToken = (token: string) => {
        const input = templateRef.current;
        const start = input?.selectionStart ?? data.template.length;
        const end = input?.selectionEnd ?? data.template.length;
        setData('template', data.template.slice(0, start) + token + data.template.slice(end));
        // The controlled value changes on the next render; restore focus and put the caret after the token.
        requestAnimationFrame(() => {
            input?.focus();
            input?.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!editing || processing) return;
        put(route('admin.number-sequences.update', editing.id), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    const nextNumber = Number.parseInt(data.next_number, 10);
    const preview = renderTemplate({
        template: data.template,
        prefix: data.prefix,
        companyCode: company_code,
        nextNumber: Number.isNaN(nextNumber) ? 1 : nextNumber,
        resetPeriod: data.reset_period,
        timeZone,
    });
    const hintError = validateTemplate(data.template, data.reset_period);
    const maxLength = editing?.max_length ?? Infinity;
    const templateError =
        errors.template ??
        hintError ??
        (preview.length > maxLength
            ? `Kode terlalu panjang: ${preview.length} dari maksimal ${maxLength} karakter.`
            : undefined);

    return (
        <AdminLayout title="Penomoran">
            <div className="space-y-6">
                <PageHeader
                    title="Penomoran"
                    subtitle="Atur awalan, format, dan reset nomor untuk kode otomatis tiap entitas."
                />
                <Alert variant="warning" role="note">
                    <AlertDescription>
                        Perubahan format hanya berlaku untuk data baru. Kode yang sudah dibuat tidak
                        berubah.
                    </AlertDescription>
                </Alert>
                <Card>
                    <CardContent className="pt-6">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Entitas</TH>
                                    <TH>Format</TH>
                                    <TH>Kode berikutnya</TH>
                                    <TH>Reset</TH>
                                    <TH>Input manual</TH>
                                    <TH className="text-right">Aksi</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {sequences.map((sequence) => (
                                    <TR key={sequence.id}>
                                        <TD className="font-medium">{sequence.label}</TD>
                                        <TD className="font-mono text-xs">{sequence.template}</TD>
                                        <TD className="font-mono">{sequence.preview}</TD>
                                        <TD>{RESET_LABELS[sequence.reset_period]}</TD>
                                        <TD>
                                            <Badge
                                                variant={
                                                    sequence.allow_manual ? 'info' : 'secondary'
                                                }
                                            >
                                                {sequence.allow_manual ? 'Boleh' : 'Tidak'}
                                            </Badge>
                                        </TD>
                                        <TD className="text-right">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                aria-label={`Ubah penomoran ${sequence.label}`}
                                                onClick={() => open(sequence)}
                                            >
                                                Ubah
                                            </Button>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Modal
                    open={editing !== null}
                    onClose={close}
                    title={editing ? `Ubah penomoran ${editing.label}` : undefined}
                    size="xl"
                >
                    <form onSubmit={submit} className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Hanya kode yang dibuat setelah ini yang memakai format baru.
                        </p>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                id="sequence-prefix"
                                label="Awalan"
                                value={data.prefix}
                                onChange={(event) => setData('prefix', event.target.value)}
                                error={errors.prefix}
                                maxLength={20}
                                required
                                className="font-mono"
                            />
                            <Input
                                id="sequence-next-number"
                                label="Nomor berikutnya"
                                type="number"
                                min={1}
                                value={data.next_number}
                                onChange={(event) => setData('next_number', event.target.value)}
                                error={errors.next_number}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Input
                                id="sequence-template"
                                ref={templateRef}
                                label="Format"
                                value={data.template}
                                onChange={(event) => setData('template', event.target.value)}
                                error={templateError}
                                required
                                spellCheck={false}
                                autoComplete="off"
                                className="font-mono"
                            />
                            <div
                                role="group"
                                aria-label="Token format"
                                className="grid gap-x-4 gap-y-1.5 sm:grid-cols-2"
                            >
                                {tokens.map(({ token, description }) => (
                                    <div key={token} className="flex items-center gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="font-mono"
                                            aria-label={`Sisipkan ${token}`}
                                            onClick={() => insertToken(token)}
                                        >
                                            {token}
                                        </Button>
                                        <span className="text-xs text-muted-foreground">
                                            {description}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                        <NativeSelect
                            id="sequence-reset"
                            label="Reset nomor"
                            value={data.reset_period}
                            onChange={(event) =>
                                setData('reset_period', event.target.value as ResetPeriod)
                            }
                            error={errors.reset_period}
                            options={RESET_OPTIONS}
                        />
                        <div className="space-y-1">
                            <Switch
                                checked={data.allow_manual}
                                onCheckedChange={(checked) => setData('allow_manual', checked)}
                                label="Izinkan input kode manual"
                            />
                            <p className="text-xs text-muted-foreground">
                                Jika mati, kolom kode di formulir dikunci dan selalu dibuat
                                otomatis.
                            </p>
                            {errors.allow_manual && (
                                <p className="text-sm text-red-700 dark:text-red-400">
                                    {errors.allow_manual}
                                </p>
                            )}
                        </div>
                        <div className="rounded-md border border-border bg-muted/50 px-3 py-2">
                            <Label htmlFor="sequence-preview">Pratinjau kode berikutnya</Label>
                            <output
                                id="sequence-preview"
                                aria-live="polite"
                                className="block font-mono text-base text-foreground"
                            >
                                {preview}
                            </output>
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={close}>
                                Batal
                            </Button>
                            <Button type="submit" loading={processing}>
                                Simpan
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
