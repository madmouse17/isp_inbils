import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Alert, AlertDescription, Button, Input, Modal, NativeSelect } from '@/Components/ui';
import { RemoteSearchSelect } from '@/Components/composite/RemoteSearchSelect';
import type { PortOption } from '@/types/ipam-odn';

const RATIOS = [2, 4, 8, 16, 32, 64] as const;

interface SplitterDialogProps {
    onClose: () => void;
    /** The ODC or ODP that gets the splitter. */
    host: { id: string; code: string };
    /** The `inputPortOptions` prop: free upstream ports that match the typed text. `undefined` until a search has run. */
    inputPortOptions: PortOption[] | undefined;
}

/** Mount it only while open, so each opening starts empty with no stale errors. */
export function SplitterDialog({ onClose, host, inputPortOptions }: SplitterDialogProps) {
    const { data, setData, post, errors, processing } = useForm({
        outputs: '8',
        input_port_id: '',
        label: '',
    });
    // The service refuses a host that is not an ODC or ODP under `asset`, which is not a field of this form.
    const assetError = (errors as Record<string, string | undefined>).asset;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.odn.splitters.store', host.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal open onClose={onClose} title={`Tambah splitter ke ${host.code}`}>
            <form onSubmit={submit} className="space-y-4">
                {assetError && (
                    <Alert variant="danger">
                        <AlertDescription>{assetError}</AlertDescription>
                    </Alert>
                )}
                <NativeSelect
                    id="splitter-outputs"
                    label="Rasio"
                    value={data.outputs}
                    onChange={(event) => setData('outputs', event.target.value)}
                    error={errors.outputs}
                    options={RATIOS.map((outputs) => ({
                        value: String(outputs),
                        label: `1:${outputs}`,
                    }))}
                    required
                />
                <RemoteSearchSelect
                    field="inputPort"
                    id="splitter-input"
                    label="Port masukan"
                    value={data.input_port_id}
                    onChange={(value) => setData('input_port_id', value)}
                    options={inputPortOptions}
                    placeholder="Cari kode aset atau label splitter"
                    emptyText="Tidak ada port kosong yang cocok."
                    error={errors.input_port_id}
                    hint="Port PON atau keluaran splitter yang masih kosong. Kosongkan untuk menghubungkan nanti."
                />
                <Input
                    id="splitter-label"
                    label="Label"
                    value={data.label}
                    onChange={(event) => setData('label', event.target.value)}
                    error={errors.label}
                    maxLength={100}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" variant="primary" loading={processing}>
                        Tambah splitter
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
