import { RemoteSearchSelect } from '@/Components/composite/RemoteSearchSelect';
import type { DropPortOption } from '@/types/ipam-odn';

interface DropPortSelectProps {
    id: string;
    value: string;
    onChange: (value: string) => void;
    /** The `dropPortOptions` prop: free ODP ports under the serving POP that match the typed text. `undefined` until a search has run. */
    options: DropPortOption[] | undefined;
    /** Why no port can be searched yet (e.g. no serving POP chosen); the field stays disabled. */
    unavailable?: string;
    error?: string;
}

/** Free ports are found by typing an ODP code or a splitter label. A full ODP is never offered, so it cannot be chosen by mistake. */
export function DropPortSelect({
    id,
    value,
    onChange,
    options,
    unavailable,
    error,
}: DropPortSelectProps) {
    return (
        <RemoteSearchSelect
            field="dropPort"
            id={id}
            label="Port drop (ODP)"
            value={value}
            onChange={onChange}
            options={options}
            placeholder="Cari kode ODP atau label splitter"
            emptyText="Tidak ada port kosong yang cocok di bawah POP ini."
            hint={unavailable ?? 'Opsional. Memesan port untuk pelanggan ini.'}
            error={error}
            disabled={Boolean(unavailable)}
        />
    );
}
