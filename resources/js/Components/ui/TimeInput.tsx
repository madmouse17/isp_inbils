import { Clock } from 'lucide-react';
import { parseTime } from '@/lib/dateValue';
import { PickerField } from './PickerField';
import type { PickerFieldShared } from './PickerField';
import { TimeSlotList } from './TimeSlotList';

export interface TimeInputProps extends PickerFieldShared {
    /** `HH:mm`, 24 hours; blank or null for no time. A time with seconds shows without them. */
    value: string | null | undefined;
    /** Called with `HH:mm`, or `''` when cleared. Never with a half-typed one. */
    onChange: (value: string) => void;
}

/**
 * A time of day you can type (`08:30`, also `8:30` or `08.30`) or pick from the half hours of the
 * day. 24 hours, as the server takes it.
 */
export function TimeInput({
    value,
    onChange,
    placeholder = 'Pilih jam',
    ...field
}: TimeInputProps) {
    const time = parseTime(value) ?? '';

    return (
        <PickerField
            {...field}
            placeholder={placeholder}
            value={time}
            onChange={onChange}
            format={(stored) => stored}
            parse={(text) => {
                if (text.trim() === '') return { ok: true, value: '' };

                const parsed = parseTime(text);

                return parsed
                    ? { ok: true, value: parsed }
                    : { ok: false, message: 'Format jam tidak valid. Contoh: 08:30.' };
            }}
            icon={<Clock className="h-4 w-4" />}
            fallbackLabel="Jam"
            openLabel="Buka daftar jam"
            clearLabel="Hapus jam"
            popoverLabel="Pilih jam"
            popoverClassName="w-40 p-1 sm:p-1"
        >
            {({ commit, close }) => (
                <TimeSlotList
                    autoFocus
                    value={time}
                    onPick={(slot) => {
                        commit(slot);
                        close();
                    }}
                    className="max-h-64"
                />
            )}
        </PickerField>
    );
}
