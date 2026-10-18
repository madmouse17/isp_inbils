import { NativeSelect } from '@/Components/composite/NativeSelect';
import { optionsOf, type StatusMap } from '@/lib/statuses';

export interface StatusFilterProps {
    /** Current `?status=`; '' is the default list (rows that are not deleted). */
    value: string;
    onChange: (value: string) => void;
    /** Adds Aktif and Nonaktif, for a master with an `is_active` flag. */
    deactivatable?: boolean;
    /** The entity's own statuses, for example `TICKET_STATUS`. */
    statuses?: StatusMap;
    id?: string;
    label?: string;
    wrapperClassName?: string;
}

/**
 * The `status` filter of an index page. The values are the ones the backend `lifecycleStatus`
 * scope reads: `aktif`, `nonaktif`, an entity status, `dihapus` (trashed only) and `semua`
 * (trashed included). The empty value is the default list, which hides trashed rows.
 */
export function StatusFilter({
    value,
    onChange,
    deactivatable = false,
    statuses,
    id = 'status-filter',
    label = 'Status',
    wrapperClassName = 'sm:w-52',
}: StatusFilterProps) {
    const options = [
        { value: '', label: 'Semua status' },
        ...(deactivatable
            ? [
                  { value: 'aktif', label: 'Aktif' },
                  { value: 'nonaktif', label: 'Nonaktif' },
              ]
            : []),
        ...(statuses ? optionsOf(statuses) : []),
        { value: 'dihapus', label: 'Dihapus' },
        { value: 'semua', label: 'Semua, termasuk dihapus' },
    ];

    return (
        <NativeSelect
            id={id}
            label={label}
            value={value}
            options={options}
            onChange={(event) => onChange(event.target.value)}
            wrapperClassName={wrapperClassName}
        />
    );
}
