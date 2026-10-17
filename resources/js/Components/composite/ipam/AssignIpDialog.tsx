import type { FormEvent } from 'react';
import { useMemo } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Alert,
    AlertDescription,
    Button,
    Input,
    Modal,
    SearchSelect,
    type SearchSelectOption,
} from '@/Components/ui';
import { formatCount } from '@/lib/ipam';
import type { StaticPool } from '@/types/ipam-odn';

interface AssignIpDialogProps {
    onClose: () => void;
    subscription: { id: string; code: string };
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
    const poolOptions = useMemo<SearchSelectOption[]>(
        () =>
            pools.map((item) => ({
                value: String(item.id),
                label: item.cidr,
                description:
                    item.free === null
                        ? 'IPv6, ketik alamatnya'
                        : `${formatCount(item.free)} alamat kosong`,
            })),
        [pools],
    );
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
        <Modal open onClose={onClose} title={`Berikan IP statis ke ${subscription.code}`}>
            <form onSubmit={submit} className="space-y-4">
                {subscriptionError && (
                    <Alert variant="danger">
                        <AlertDescription>{subscriptionError}</AlertDescription>
                    </Alert>
                )}
                <SearchSelect
                    id="assign-ip-pool"
                    label="Pool"
                    value={data.ip_prefix_id}
                    onChange={(value) => setData('ip_prefix_id', value)}
                    options={poolOptions}
                    placeholder="Pilih pool..."
                    emptyText="Tidak ada pool yang cocok."
                    error={errors.ip_prefix_id}
                    required
                />
                <Input
                    id="assign-ip-address"
                    label="Alamat"
                    value={data.address}
                    onChange={(event) => setData('address', event.target.value)}
                    error={errors.address}
                    hint={
                        pool?.family === 6
                            ? 'Alamat IPv6 diketik manual.'
                            : 'Kosongkan untuk mengambil alamat kosong terendah di pool.'
                    }
                    placeholder={pool?.family === 6 ? '2001:db8::1' : 'Alamat kosong terendah'}
                    autoComplete="off"
                    spellCheck={false}
                    required={pool?.family === 6}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" variant="primary" loading={processing}>
                        Berikan alamat
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
