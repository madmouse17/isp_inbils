import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Input, Modal, NativeSelect } from '@/Components/ui';
import { usageHints, usageOptions } from '@/lib/ipam';
import type { IpUsage, PopOption, PrefixRow, VlanOption } from '@/types/ipam-odn';

interface PrefixDialogProps {
    onClose: () => void;
    /** The prefix being edited; its CIDR is fixed. Omit to register a new prefix. */
    prefix?: PrefixRow;
    locations: PopOption[];
    vlans: VlanOption[];
}

/** Mount it only while open: each opening then starts from the prefix (or an empty form) with no stale errors. */
export function PrefixDialog({ onClose, prefix, locations, vlans }: PrefixDialogProps) {
    const form = useForm({
        cidr: prefix?.cidr ?? '',
        usage: prefix?.usage ?? ('' as const),
        location_id: prefix?.location ? String(prefix.location.id) : '',
        vlan_id: prefix?.vlan ? String(prefix.vlan.id) : '',
        description: prefix?.description ?? '',
    });
    const { data, setData, errors, processing } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (prefix) {
            form.put(route('admin.ipam.prefixes.update', prefix.id), options);
        } else {
            form.post(route('admin.ipam.prefixes.store'), options);
        }
    };

    return (
        <Modal open onClose={onClose} title={prefix ? `Edit ${prefix.cidr}` : 'New prefix'}>
            <form onSubmit={submit} className="space-y-4">
                <Input
                    id="prefix-cidr"
                    label="CIDR"
                    value={data.cidr}
                    onChange={(event) => setData('cidr', event.target.value)}
                    error={errors.cidr}
                    placeholder="10.20.0.0/22 or 2001:db8::/48"
                    hint={
                        prefix
                            ? 'A prefix keeps its CIDR. Register a new one to change it.'
                            : undefined
                    }
                    disabled={prefix !== undefined}
                    autoComplete="off"
                    spellCheck={false}
                    required
                />
                <NativeSelect
                    id="prefix-usage"
                    label="Usage"
                    value={data.usage}
                    onChange={(event) => setData('usage', event.target.value as IpUsage | '')}
                    error={errors.usage}
                    hint={data.usage ? usageHints[data.usage] : undefined}
                    options={[{ value: '', label: 'Select usage…' }, ...usageOptions]}
                    required
                />
                <NativeSelect
                    id="prefix-pop"
                    label="POP"
                    value={data.location_id}
                    onChange={(event) => setData('location_id', event.target.value)}
                    error={errors.location_id}
                    options={[
                        { value: '', label: 'Not tied to a POP' },
                        ...locations.map((pop) => ({
                            value: String(pop.id),
                            label: `${pop.code} - ${pop.name}`,
                        })),
                    ]}
                />
                <NativeSelect
                    id="prefix-vlan"
                    label="VLAN"
                    value={data.vlan_id}
                    onChange={(event) => setData('vlan_id', event.target.value)}
                    error={errors.vlan_id}
                    options={[
                        { value: '', label: 'No VLAN' },
                        ...vlans.map((vlan) => ({
                            value: String(vlan.id),
                            label: `VLAN ${vlan.vid} · ${vlan.name} (${vlan.location_code ?? 'company-wide'})`,
                        })),
                    ]}
                />
                <Input
                    id="prefix-description"
                    label="Description"
                    value={data.description}
                    onChange={(event) => setData('description', event.target.value)}
                    error={errors.description}
                    maxLength={255}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" loading={processing}>
                        {prefix ? 'Save' : 'Register prefix'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
