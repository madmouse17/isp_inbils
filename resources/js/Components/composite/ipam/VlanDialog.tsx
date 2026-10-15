import type { FormEvent } from 'react';
import { useMemo } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Input, Modal, SearchSelect, type SearchSelectOption } from '@/Components/ui';
import type { PopOption, VlanRow } from '@/types/ipam-odn';

interface VlanDialogProps {
    onClose: () => void;
    /** The VLAN being edited. Omit to register a new one. */
    vlan?: VlanRow;
    locations: PopOption[];
}

/** Mount it only while open: each opening then starts from the VLAN (or an empty form) with no stale errors. */
export function VlanDialog({ onClose, vlan, locations }: VlanDialogProps) {
    const form = useForm({
        vid: vlan ? String(vlan.vid) : '',
        name: vlan?.name ?? '',
        location_id: vlan?.location ? String(vlan.location.id) : '',
        description: vlan?.description ?? '',
    });
    const { data, setData, errors, processing } = form;

    const popOptions = useMemo<SearchSelectOption[]>(
        () =>
            locations.map((pop) => ({ value: String(pop.id), label: `${pop.code} - ${pop.name}` })),
        [locations],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (vlan) {
            form.put(route('admin.ipam.vlans.update', vlan.id), options);
        } else {
            form.post(route('admin.ipam.vlans.store'), options);
        }
    };

    return (
        <Modal open onClose={onClose} title={vlan ? `Ubah VLAN ${vlan.vid}` : 'Buat VLAN'}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-[8rem_1fr]">
                    <Input
                        id="vlan-vid"
                        label="ID VLAN"
                        type="number"
                        min={1}
                        max={4094}
                        value={data.vid}
                        onChange={(event) => setData('vid', event.target.value)}
                        error={errors.vid}
                        required
                    />
                    <Input
                        id="vlan-name"
                        label="Nama"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        error={errors.name}
                        maxLength={64}
                        required
                    />
                </div>
                <SearchSelect
                    id="vlan-pop"
                    label="POP"
                    value={data.location_id}
                    onChange={(value) => setData('location_id', value)}
                    options={popOptions}
                    placeholder="Seluruh perusahaan"
                    emptyText="Tidak ada POP yang cocok."
                    error={errors.location_id}
                    hint="ID VLAN yang sama boleh ada di beberapa POP, tetapi hanya sekali per POP."
                />
                <Input
                    id="vlan-description"
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
                        {vlan ? 'Simpan' : 'Daftarkan VLAN'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
