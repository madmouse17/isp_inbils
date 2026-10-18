import { CalendarDays } from 'lucide-react';
import { DayPicker } from 'react-day-picker';
import { id as calendarLocale } from 'react-day-picker/locale/id';
import { useCompany } from '@/hooks/useCompany';
import { phpToDateFns } from '@/lib/dateFormat';
import { formatDay, isoToLocalDate, localDateToIso, parseDay, todayInZone } from '@/lib/dateRange';
import { Button } from './Button';
import { PickerField } from './PickerField';
import type { PickerFieldShared } from './PickerField';
import { SELECTED_DAY_CLASSES } from './pickerCell';

export interface DatePickerProps extends PickerFieldShared {
    /** ISO `yyyy-mm-dd`; blank or null for no date. */
    value: string | null | undefined;
    /** Called with an ISO day, or `''` when the date is cleared. Never with a half-typed one. */
    onChange: (value: string) => void;
}

/**
 * One date you can type or pick, written in the company `date_format` and sent as ISO. Same look
 * and typing rules as `DateRangePicker`: the text is committed on blur or Enter, only when it is a
 * real day, and the calendar commits when a day is chosen.
 */
export function DatePicker({
    value,
    onChange,
    placeholder = 'Pilih tanggal',
    ...field
}: DatePickerProps) {
    const company = useCompany();
    const dateFnsFormat = phpToDateFns(company?.settings?.date_format);
    const today = todayInZone(company?.timezone);
    const iso = value ?? '';

    return (
        <PickerField
            {...field}
            placeholder={placeholder}
            value={iso}
            onChange={onChange}
            format={(day) => formatDay(day, dateFnsFormat)}
            parse={(text) => {
                if (text.trim() === '') return { ok: true, value: '' };

                const day = parseDay(text, dateFnsFormat);

                return day
                    ? { ok: true, value: day }
                    : {
                          ok: false,
                          message: `Format tanggal tidak valid. Contoh: ${formatDay(today, dateFnsFormat)}.`,
                      };
            }}
            icon={<CalendarDays className="h-4 w-4" />}
            fallbackLabel="Tanggal"
            openLabel="Buka kalender"
            clearLabel="Hapus tanggal"
            popoverLabel="Pilih tanggal"
        >
            {({ commit, close }) => (
                <div className="space-y-2">
                    <DayPicker
                        mode="single"
                        required
                        autoFocus
                        locale={calendarLocale}
                        selected={isoToLocalDate(iso) ?? undefined}
                        onSelect={(day) => {
                            commit(localDateToIso(day));
                            close();
                        }}
                        defaultMonth={isoToLocalDate(iso || today) ?? undefined}
                        today={isoToLocalDate(today) ?? undefined}
                        modifiersClassNames={SELECTED_DAY_CLASSES}
                        navLayout="around"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-full"
                        onClick={() => {
                            commit(today);
                            close();
                        }}
                    >
                        Hari ini
                    </Button>
                </div>
            )}
        </PickerField>
    );
}
