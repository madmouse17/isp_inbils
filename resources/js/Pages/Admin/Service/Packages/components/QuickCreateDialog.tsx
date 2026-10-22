import { useState } from 'react';
import type { FormEvent } from 'react';
import axios from 'axios';
import { Button, Input, NativeSelect } from '@/Components/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';

/** The master rows a package points to, created without leaving the package form. */
export type QuickCreateKind = 'bandwidth' | 'speed' | 'sla';

/** What the store endpoints answer to the dialog's request (X-Inline-Create): the new row's public id and its name. */
export interface CreatedOption {
    id: string;
    label: string;
}

interface Field {
    key: string;
    label: string;
    number?: boolean;
}

const KINDS: Record<
    QuickCreateKind,
    { title: string; description: string; route: string; fields: Field[] }
> = {
    bandwidth: {
        title: 'Profil bandwidth baru',
        description: 'Kecepatan yang dijual dan jenis pembagiannya.',
        route: 'admin.bandwidth-profiles.store',
        fields: [
            { key: 'name', label: 'Nama' },
            { key: 'download_mbps', label: 'Unduh (Mbps)', number: true },
            { key: 'upload_mbps', label: 'Unggah (Mbps)', number: true },
        ],
    },
    speed: {
        title: 'Profil kecepatan baru',
        description: 'Batas kecepatan yang dipasang di router.',
        route: 'admin.speed-profiles.store',
        fields: [
            { key: 'name', label: 'Nama' },
            { key: 'download_max_mbps', label: 'Unduh maksimum (Mbps)', number: true },
            { key: 'upload_max_mbps', label: 'Unggah maksimum (Mbps)', number: true },
        ],
    },
    sla: {
        title: 'Tingkat SLA baru',
        description:
            'Target dihitung 24 jam. Ubah hitungan tenggat di halaman Tingkat SLA bila perlu.',
        route: 'admin.sla-tiers.store',
        fields: [
            { key: 'name', label: 'Nama' },
            { key: 'response_time_hours', label: 'Target respons (jam)', number: true },
            { key: 'resolution_time_hours', label: 'Target penyelesaian (jam)', number: true },
        ],
    },
};

const BANDWIDTH_TYPES = [
    { value: 'shared', label: 'Shared' },
    { value: 'dedicated', label: 'Dedicated' },
];

interface QuickCreateDialogProps {
    kind: QuickCreateKind | null;
    onClose: () => void;
    onCreated: (
        kind: QuickCreateKind,
        option: CreatedOption,
        submitted: Record<string, string | boolean>,
    ) => void;
    /** Speeds the new profile starts with: those of the bandwidth profile the package form holds. */
    speedDefaults?: { download: number | null; upload: number | null };
}

export function QuickCreateDialog({
    kind,
    onClose,
    onCreated,
    speedDefaults,
}: QuickCreateDialogProps) {
    return (
        <Dialog open={kind !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                {kind !== null && (
                    <QuickCreateForm
                        key={kind}
                        kind={kind}
                        onClose={onClose}
                        onCreated={onCreated}
                        speedDefaults={speedDefaults}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function QuickCreateForm({
    kind,
    onClose,
    onCreated,
    speedDefaults,
}: Omit<QuickCreateDialogProps, 'kind'> & { kind: QuickCreateKind }) {
    const config = KINDS[kind];
    const [values, setValues] = useState<Record<string, string>>(() => ({
        name: '',
        download_mbps: '',
        upload_mbps: '',
        type: 'shared',
        contention_ratio: '4',
        download_max_mbps: speedDefaults?.download != null ? String(speedDefaults.download) : '',
        upload_max_mbps: speedDefaults?.upload != null ? String(speedDefaults.upload) : '',
        response_time_hours: '',
        resolution_time_hours: '',
    }));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [failure, setFailure] = useState<string>();

    const set = (key: string, value: string) =>
        setValues((current) => ({ ...current, [key]: value }));

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        // The dialog sits inside the package page in the React tree: its submit must not reach the package form.
        event.stopPropagation();

        const payload: Record<string, string | boolean> = { is_active: true };
        for (const field of config.fields) payload[field.key] = values[field.key];
        if (kind === 'bandwidth') {
            payload.type = values.type;
            if (values.type === 'shared') payload.contention_ratio = values.contention_ratio;
        }

        setProcessing(true);
        setErrors({});
        setFailure(undefined);
        try {
            const { data } = await axios.post<{ data: CreatedOption }>(
                route(config.route),
                payload,
                {
                    // Accept: JSON keeps a refusal a 422; X-Inline-Create asks for the new row instead of the form redirect.
                    headers: { Accept: 'application/json', 'X-Inline-Create': '1' },
                },
            );
            onCreated(kind, data.data, payload);
            onClose();
        } catch (error) {
            const response = axios.isAxiosError<{ errors?: Record<string, string[]> }>(error)
                ? error.response
                : undefined;
            if (response?.status === 422 && response.data.errors) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(response.data.errors).map(([key, messages]) => [
                            key,
                            messages[0],
                        ]),
                    ),
                );
            } else {
                setFailure('Gagal menyimpan. Coba lagi.');
            }
        } finally {
            setProcessing(false);
        }
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>{config.title}</DialogTitle>
                <DialogDescription>{config.description}</DialogDescription>
            </DialogHeader>
            <form onSubmit={(event) => void submit(event)} className="space-y-4">
                {config.fields.map((field, index) => (
                    <Input
                        key={field.key}
                        label={field.label}
                        type={field.number ? 'number' : 'text'}
                        min={field.number ? 1 : undefined}
                        value={values[field.key]}
                        onChange={(e) => set(field.key, e.target.value)}
                        error={errors[field.key]}
                        autoFocus={index === 0}
                        required
                    />
                ))}
                {kind === 'bandwidth' && (
                    <>
                        <NativeSelect
                            label="Tipe"
                            value={values.type}
                            onChange={(e) => set('type', e.target.value)}
                            options={BANDWIDTH_TYPES}
                            error={errors.type}
                            required
                        />
                        {values.type === 'shared' && (
                            <Input
                                label="Rasio kontensi"
                                type="number"
                                min={1}
                                value={values.contention_ratio}
                                onChange={(e) => set('contention_ratio', e.target.value)}
                                error={errors.contention_ratio}
                                required
                            />
                        )}
                    </>
                )}
                {failure && (
                    <p role="alert" className="text-sm text-destructive-text">
                        {failure}
                    </p>
                )}
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Batal
                    </Button>
                    <Button type="submit" variant="primary" loading={processing}>
                        Simpan
                    </Button>
                </DialogFooter>
            </form>
        </>
    );
}
