import { NativeSelect } from '@/Components/ui';
import type { DropPortOption } from '@/types/ipam-odn';

interface DropPortSelectProps {
    id: string;
    value: string;
    onChange: (value: string) => void;
    /** Free ODP ports under the serving POP; undefined until a partial reload delivered them. */
    options: DropPortOption[] | undefined;
    /** True while that partial reload is in flight. */
    loading: boolean;
    /** Why no list can be offered yet (e.g. no serving POP chosen); the select stays disabled. */
    unavailable?: string;
    error?: string;
}

/** Free ports grouped under their ODP. A full ODP is never offered, so it cannot be chosen by mistake. */
export function DropPortSelect({
    id,
    value,
    onChange,
    options,
    loading,
    unavailable,
    error,
}: DropPortSelectProps) {
    const groups = new Map<string, DropPortOption[]>();

    for (const option of options ?? []) {
        groups.set(option.odp_code, [...(groups.get(option.odp_code) ?? []), option]);
    }

    const empty = options !== undefined && options.length === 0;
    const hint = unavailable
        ? unavailable
        : loading
          ? 'Loading free ports…'
          : empty
            ? 'No free port under this POP'
            : 'Optional. Reserves the port for this customer.';

    return (
        <NativeSelect
            id={id}
            label="Drop port (ODP)"
            value={value}
            onChange={(event) => onChange(event.target.value)}
            error={error}
            hint={hint}
            disabled={Boolean(unavailable) || loading || empty || options === undefined}
        >
            <option value="">No port chosen</option>
            {[...groups].map(([code, ports]) => (
                <optgroup
                    key={code}
                    label={`${code} — ${ports[0].location_path} (${ports[0].free_count} free)`}
                >
                    {ports.map((port) => (
                        <option key={port.port_id} value={port.port_id}>
                            {port.label}
                        </option>
                    ))}
                </optgroup>
            ))}
        </NativeSelect>
    );
}
