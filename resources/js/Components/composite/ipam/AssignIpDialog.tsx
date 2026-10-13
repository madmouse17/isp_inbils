import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Alert, AlertDescription, Button, Input, Modal, NativeSelect } from '@/Components/ui';
import { formatCount } from '@/lib/ipam';
import type { StaticPool } from '@/types/ipam-odn';

interface AssignIpDialogProps {
    onClose: () => void;
    subscription: { id: number; code: string };
    /** Pools the subscription does not already hold an address from. */
    pools: StaticPool[];
}

/** Mount it only while open, so each opening starts empty with no stale errors. */
export function AssignIpDialog({ onClose, subscription, pools }: AssignIpDialogProps) {
    const { data, setData, post, errors, processing } = useForm({
        ip_prefix_id: pools.length === 1 ? String(pools[0].id) : '',
        address: '',
    });
    const pool = pools.find((item) => String(item.id) === data.ip_prefix_id);
    // The service refuses a terminated subscription under `subscription`, which is not a field of this form.
    const subscriptionError = (errors as Record<string, string | undefined>).subscription;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.ipam.subscription-addresses.store', subscription.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal open onClose={onClose} title={`Assign a static IP to ${subscription.code}`}>
            <form onSubmit={submit} className="space-y-4">
                {subscriptionError && (
                    <Alert variant="danger">
                        <AlertDescription className="text-red-700 dark:text-red-400">
                            {subscriptionError}
                        </AlertDescription>
                    </Alert>
                )}
                <NativeSelect
                    id="assign-ip-pool"
                    label="Pool"
                    value={data.ip_prefix_id}
                    onChange={(event) => setData('ip_prefix_id', event.target.value)}
                    error={errors.ip_prefix_id}
                    options={[
                        { value: '', label: 'Select a pool…' },
                        ...pools.map((item) => ({
                            value: String(item.id),
                            label:
                                item.free === null
                                    ? `${item.cidr} (IPv6, type the address)`
                                    : `${item.cidr} (${formatCount(item.free)} free)`,
                        })),
                    ]}
                    required
                />
                <Input
                    id="assign-ip-address"
                    label="Address"
                    value={data.address}
                    onChange={(event) => setData('address', event.target.value)}
                    error={errors.address}
                    hint={
                        pool?.family === 6
                            ? 'IPv6 addresses are typed by hand.'
                            : 'Leave empty to take the lowest free address in the pool.'
                    }
                    placeholder={pool?.family === 6 ? '2001:db8::1' : 'Lowest free address'}
                    autoComplete="off"
                    spellCheck={false}
                    required={pool?.family === 6}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" loading={processing}>
                        Assign address
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
