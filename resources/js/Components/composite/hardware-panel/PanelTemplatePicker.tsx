import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import {
    Alert,
    AlertDescription,
    Button,
    IconButton,
    Input,
    NativeSelect,
    SearchSelect,
} from '@/Components/ui';
import type { PanelPayload, PanelTemplate, PortKind } from '@/types/hardware-panel';
import {
    MANUAL_MAX_COUNT,
    MANUAL_MAX_LINES,
    MANUAL_PORT_LIMIT,
    manualKinds,
    manualLayout,
    manualNames,
    manualProblem,
    manualTotal,
} from './manualLayout.helpers';

export interface PanelTemplatePickerProps {
    asset: PanelPayload['asset'];
    /** The catalogue rows of this asset type; null when it has none, so only "Susun manual" is left. */
    picker: NonNullable<PanelPayload['picker']> | null;
    canUpdate: boolean;
    /** Set when changing the model of a unit that has one. */
    current?: PanelTemplate | null;
    onDone?: () => void;
}

const KIND_LABEL: Record<PortKind, string> = {
    rj45: 'RJ45 (kabel LAN)',
    sfp: 'SFP / SFP+ (fiber)',
    sc: 'SC (fiber)',
    rj11: 'RJ11 (telepon)',
};

/** A line of the manual form; the count stays text while it is typed, so an empty field is not a 0. */
interface Row {
    kind: PortKind;
    count: string;
    prefix: string;
}

/** The first message of the layout rules, whichever block it is about (`layout.rows.0.0.count`). */
function layoutRefusal(errors: Record<string, string | undefined>): string | undefined {
    return (
        errors.ports ??
        errors.layout ??
        errors.height_u ??
        Object.entries(errors).find(([key]) => key.startsWith('layout.'))?.[1]
    );
}

/**
 * Gives a unit its panel: a model from the catalogue (the suggested one is preselected) or ports
 * entered by hand. Refusals of the server stay in the form, so a model that would drop a linked
 * port says which one.
 */
export function PanelTemplatePicker({
    asset,
    picker,
    canUpdate,
    current = null,
    onDone,
}: PanelTemplatePickerProps) {
    if (!canUpdate) {
        return (
            <p className="text-sm text-muted-foreground">
                Model perangkat belum dipilih. Minta admin memilih model agar panel tampil.
            </p>
        );
    }

    return <PickerForm asset={asset} picker={picker} current={current} onDone={onDone} />;
}

function PickerForm({
    asset,
    picker,
    current,
    onDone,
}: Omit<PanelTemplatePickerProps, 'canUpdate'> & { current: PanelTemplate | null }) {
    // ODC and ODP take their ports from splitters, so the server refuses a layout of their own.
    const manualAllowed = asset.type !== 'odc' && asset.type !== 'odp';
    const kinds = manualKinds(asset.type);
    const [mode, setMode] = useState<'catalogue' | 'manual'>(
        picker !== null || !manualAllowed ? 'catalogue' : 'manual',
    );
    const [rows, setRows] = useState<Row[]>([{ kind: kinds[0], count: '8', prefix: '' }]);

    const options = useMemo(
        () =>
            (picker?.options ?? []).map((option) => ({
                value: option.key,
                label: option.label,
                description: option.height_u === null ? undefined : `${option.height_u}U`,
            })),
        [picker],
    );
    const initial =
        options.find((option) => option.value === current?.key) ??
        options.find((option) => option.value === picker?.suggested);
    const form = useForm({ template_key: initial?.value ?? '' });
    const errors = form.errors as Record<string, string | undefined>;
    const refusal = layoutRefusal(errors);

    const lines = rows.map((row) => ({
        ...row,
        count: row.count.trim() === '' ? NaN : Number(row.count),
    }));
    const problem = mode === 'manual' ? manualProblem(lines) : null;
    const total = manualTotal(lines);
    const ready = mode === 'manual' ? problem === null : form.data.template_key !== '';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) =>
            mode === 'catalogue'
                ? { template_key: data.template_key }
                : { layout: manualLayout(lines, asset.type) },
        );
        form.put(route('admin.network-assets.panel.update', asset.id), {
            preserveScroll: true,
            onSuccess: () => onDone?.(),
        });
    };

    const setRow = (index: number, change: Partial<Row>) =>
        setRows((all) => all.map((row, i) => (i === index ? { ...row, ...change } : row)));

    return (
        <form onSubmit={submit} className="space-y-4">
            {mode === 'catalogue' ? (
                <>
                    {current === null ? (
                        <p className="text-sm text-muted-foreground">
                            Panel digambar dari model perangkat. Pilih dari katalog
                            {manualAllowed ? ' atau susun port sendiri.' : '.'}
                        </p>
                    ) : null}
                    <SearchSelect
                        id="panel-template"
                        label="Model perangkat"
                        value={form.data.template_key}
                        onChange={(value) => form.setData('template_key', value)}
                        options={options}
                        placeholder="Cari vendor atau model"
                        emptyText="Tidak ada model yang cocok."
                        hint={
                            picker?.suggested && form.data.template_key === picker.suggested
                                ? 'Disarankan dari vendor dan model aset ini.'
                                : undefined
                        }
                        error={errors.template_key}
                        required
                    />
                </>
            ) : (
                <fieldset className="space-y-3">
                    <legend className="text-sm text-muted-foreground">
                        Susun port perangkat ini: jenis, jumlah, dan awalan nama tiap baris.
                    </legend>
                    {rows.map((row, index) => (
                        <div
                            key={index}
                            role="group"
                            aria-label={`Baris ${index + 1}`}
                            className="grid gap-3 sm:grid-cols-[minmax(0,1.4fr)_6rem_minmax(0,1fr)_auto] sm:items-end"
                        >
                            <NativeSelect
                                id={`manual-kind-${index}`}
                                label="Jenis port"
                                value={row.kind}
                                onChange={(event) =>
                                    setRow(index, { kind: event.target.value as PortKind })
                                }
                                options={kinds.map((kind) => ({
                                    value: kind,
                                    label: KIND_LABEL[kind],
                                }))}
                            />
                            <Input
                                id={`manual-count-${index}`}
                                label="Jumlah"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={MANUAL_MAX_COUNT}
                                step={1}
                                value={row.count}
                                onChange={(event) => setRow(index, { count: event.target.value })}
                            />
                            <Input
                                id={`manual-prefix-${index}`}
                                label="Awalan nama"
                                value={row.prefix}
                                maxLength={20}
                                placeholder={manualNames(row.kind, asset.type).prefix}
                                onChange={(event) => setRow(index, { prefix: event.target.value })}
                            />
                            <IconButton
                                type="button"
                                label={`Hapus baris ${index + 1}`}
                                variant="destructive-outline"
                                disabled={rows.length <= 1}
                                onClick={() => setRows((all) => all.filter((_, i) => i !== index))}
                            >
                                <Trash2 className="size-4" aria-hidden="true" />
                            </IconButton>
                        </div>
                    ))}
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            leftIcon={<Plus className="size-4" aria-hidden="true" />}
                            disabled={rows.length >= MANUAL_MAX_LINES}
                            onClick={() =>
                                setRows((all) => [
                                    ...all,
                                    { kind: kinds[0], count: '1', prefix: '' },
                                ])
                            }
                        >
                            Tambah baris
                        </Button>
                        <p aria-live="polite" className="text-sm text-muted-foreground">
                            Total {total} port (paling banyak {MANUAL_PORT_LIMIT})
                        </p>
                    </div>
                    {problem ? <p className="text-sm text-destructive-text">{problem}</p> : null}
                </fieldset>
            )}

            {refusal ? (
                <Alert variant="danger">
                    <AlertDescription>{refusal}</AlertDescription>
                </Alert>
            ) : null}
            {form.recentlySuccessful ? (
                <p role="status" className="text-sm text-muted-foreground">
                    Model diterapkan. Panel sedang digambar.
                </p>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="submit"
                    variant="primary"
                    loading={form.processing}
                    disabled={!ready || form.recentlySuccessful}
                >
                    Terapkan model
                </Button>
                {manualAllowed && picker !== null ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setMode(mode === 'catalogue' ? 'manual' : 'catalogue')}
                    >
                        {mode === 'catalogue' ? 'Susun manual' : 'Pilih dari katalog'}
                    </Button>
                ) : null}
            </div>
        </form>
    );
}
