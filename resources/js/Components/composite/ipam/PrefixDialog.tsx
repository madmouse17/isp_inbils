import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Button,
    Input,
    Modal,
    NativeSelect,
    SearchSelect,
    type SearchSelectOption,
} from '@/Components/ui';
import { usageHints } from '@/lib/ipam';
import { IP_PREFIX_USAGE, optionsOf } from '@/lib/statuses';
import { parentPrefixOf } from '@/lib/suggest';
import type { IpUsage, PopOption, PrefixRow, VlanOption } from '@/types/ipam-odn';

interface PrefixDialogProps {
    onClose: () => void;
    /** The prefix being edited; its CIDR is fixed. Omit to register a new prefix. */
    prefix?: PrefixRow;
    locations: PopOption[];
    vlans: VlanOption[];
    /** Every prefix of the company, to find the parent of a new CIDR and offer its VLAN. Omit when the list is incomplete. */
    prefixes?: PrefixRow[];
}

const usageOptions = [{ value: '', label: 'Pilih peruntukan...' }, ...optionsOf(IP_PREFIX_USAGE)];

/** Mount it only while open: each opening then starts from the prefix (or an empty form) with no stale errors. */
export function PrefixDialog({ onClose, prefix, locations, vlans, prefixes }: PrefixDialogProps) {
    const form = useForm({
        cidr: prefix?.cidr ?? '',
        usage: prefix?.usage ?? ('' as const),
        location_id: prefix?.location ? String(prefix.location.id) : '',
        vlan_id: prefix?.vlan ? String(prefix.vlan.id) : '',
        description: prefix?.description ?? '',
    });
    const { data, setData, errors, processing } = form;
    // The parent's VLAN fills the field until the person chooses one; an edit keeps what the prefix has.
    const [vlanPicked, setVlanPicked] = useState(prefix !== undefined);
    const [inheritedFrom, setInheritedFrom] = useState<string | null>(null);

    const changeCidr = (cidr: string) => {
        if (vlanPicked || !prefixes) {
            setData('cidr', cidr);
            return;
        }

        const parent = parentPrefixOf(cidr, prefixes);
        setData((previous) => ({
            ...previous,
            cidr,
            vlan_id: parent?.vlan ? String(parent.vlan.id) : '',
        }));
        setInheritedFrom(parent?.vlan ? parent.cidr : null);
    };

    const pickVlan = (value: string) => {
        setVlanPicked(true);
        setInheritedFrom(null);
        setData('vlan_id', value);
    };

    const popOptions = useMemo<SearchSelectOption[]>(
        () =>
            locations.map((pop) => ({ value: String(pop.id), label: `${pop.code} - ${pop.name}` })),
        [locations],
    );
    const vlanOptions = useMemo<SearchSelectOption[]>(
        () =>
            vlans.map((vlan) => ({
                value: String(vlan.id),
                label: `VLAN ${vlan.vid} - ${vlan.name}`,
                description: vlan.location_code ?? 'Seluruh perusahaan',
            })),
        [vlans],
    );

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
        <Modal open onClose={onClose} title={prefix ? `Ubah ${prefix.cidr}` : 'Buat Prefiks'}>
            <form onSubmit={submit} className="space-y-4">
                <Input
                    id="prefix-cidr"
                    label="CIDR"
                    value={data.cidr}
                    onChange={(event) => changeCidr(event.target.value)}
                    error={errors.cidr}
                    placeholder="10.20.0.0/22 atau 2001:db8::/48"
                    hint={
                        prefix
                            ? 'Prefiks tetap memakai CIDR-nya. Daftarkan yang baru untuk mengubahnya.'
                            : undefined
                    }
                    disabled={prefix !== undefined}
                    autoComplete="off"
                    spellCheck={false}
                    required
                />
                <NativeSelect
                    id="prefix-usage"
                    label="Peruntukan"
                    value={data.usage}
                    onChange={(event) => setData('usage', event.target.value as IpUsage | '')}
                    error={errors.usage}
                    hint={data.usage ? usageHints[data.usage] : undefined}
                    options={usageOptions}
                    required
                />
                <SearchSelect
                    id="prefix-pop"
                    label="POP"
                    value={data.location_id}
                    onChange={(value) => setData('location_id', value)}
                    options={popOptions}
                    placeholder="Tidak terkait POP"
                    emptyText="Tidak ada POP yang cocok."
                    error={errors.location_id}
                />
                <SearchSelect
                    id="prefix-vlan"
                    label="VLAN"
                    value={data.vlan_id}
                    onChange={pickVlan}
                    hint={inheritedFrom ? `Mengikuti prefiks induk ${inheritedFrom}.` : undefined}
                    options={vlanOptions}
                    placeholder="Tanpa VLAN"
                    emptyText="Tidak ada VLAN yang cocok."
                    error={errors.vlan_id}
                />
                <Input
                    id="prefix-description"
                    label="Keterangan"
                    value={data.description}
                    onChange={(event) => setData('description', event.target.value)}
                    error={errors.description}
                    maxLength={255}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" variant="primary" loading={processing}>
                        {prefix ? 'Simpan' : 'Daftarkan prefiks'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
