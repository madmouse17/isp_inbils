import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Alert, AlertDescription, Button, Input, Modal, NativeSelect } from '@/Components/ui';
import type { InputOption } from '@/types/ipam-odn';

const RATIOS = [2, 4, 8, 16, 32, 64] as const;

interface SplitterDialogProps {
    onClose: () => void;
    /** The ODC or ODP that gets the splitter. */
    host: { id: number; code: string };
    inputOptions: InputOption[];
}

/** Mount it only while open, so each opening starts empty with no stale errors. */
export function SplitterDialog({ onClose, host, inputOptions }: SplitterDialogProps) {
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
        <Modal open onClose={onClose} title={`Add a splitter to ${host.code}`}>
            <form onSubmit={submit} className="space-y-4">
                {assetError && (
                    <Alert variant="danger">
                        <AlertDescription className="text-red-700 dark:text-red-400">
                            {assetError}
                        </AlertDescription>
                    </Alert>
                )}
                <NativeSelect
                    id="splitter-outputs"
                    label="Ratio"
                    value={data.outputs}
                    onChange={(event) => setData('outputs', event.target.value)}
                    error={errors.outputs}
                    options={RATIOS.map((outputs) => ({
                        value: String(outputs),
                        label: `1:${outputs}`,
                    }))}
                    required
                />
                <NativeSelect
                    id="splitter-input"
                    label="Upstream port"
                    value={data.input_port_id}
                    onChange={(event) => setData('input_port_id', event.target.value)}
                    error={errors.input_port_id}
                    hint="A free PON port or splitter output. Leave it open to connect later."
                    options={[
                        { value: '', label: 'Not connected yet' },
                        ...inputOptions.map((option) => ({
                            value: String(option.port_id),
                            label: option.label,
                        })),
                    ]}
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
                        Cancel
                    </Button>
                    <Button type="submit" variant="success" loading={processing}>
                        Add splitter
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
