import { CalendarClock } from 'lucide-react';
import { DayPicker } from 'react-day-picker';
import { id as calendarLocale } from 'react-day-picker/locale/id';
import { useCompany } from '@/hooks/useCompany';
import { phpToDateFns } from '@/lib/dateFormat';
import { formatDay, isoToLocalDate, localDateToIso, todayInZone } from '@/lib/dateRange';
import { formatDayTime, joinDayTime, parseDayTime, splitDayTime } from '@/lib/dateValue';
import { Button } from './Button';
import { PickerField } from './PickerField';
import type { PickerFieldShared } from './PickerField';
import { SELECTED_DAY_CLASSES } from './pickerCell';
import { TimeSlotList } from './TimeSlotList';

export interface DateTimePickerProps extends PickerFieldShared {
    /** `Y-m-d H:i` on the company clock (`2026-10-13 08:30`); blank or null for none. */
    value: string | null | undefined;
    /** Called with `Y-m-d H:i`, or `''` when cleared. Never with a half-typed one. */
    onChange: (value: string) => void;
}

/**
 * A date and a time you can type (`13 Okt 2026 08:30`, the day in the company `date_format`, the
 * time as `HH:mm`) or pick: a calendar and the half hours of the day. A day picked without a time
 * starts at 00:00; a time picked without a day takes today.
 */
export function DateTimePicker({
    value,
    onChange,
    placeholder = 'Pilih tanggal dan jam',
    ...field
}: DateTimePickerProps) {
    const company = useCompany();
    const dateFnsFormat = phpToDateFns(company?.settings?.date_format);
    const today = todayInZone(company?.timezone);
    const parts = splitDayTime(value);

    return (
        <PickerField
            {...field}
            placeholder={placeholder}
            value={parts ? joinDayTime(parts.day, parts.time) : ''}
            onChange={onChange}
            format={(stored) => formatDayTime(stored, dateFnsFormat)}
            parse={(text) => {
                if (text.trim() === '') return { ok: true, value: '' };

                const stored = parseDayTime(text, dateFnsFormat);

                return stored
                    ? { ok: true, value: stored }
                    : {
                          ok: false,
                          message: `Format tanggal dan jam tidak valid. Contoh: ${formatDay(today, dateFnsFormat)} 08:30.`,
                      };
            }}
            icon={<CalendarClock className="h-4 w-4" />}
            fallbackLabel="Tanggal dan jam"
            openLabel="Buka kalender"
            clearLabel="Hapus tanggal dan jam"
            popoverLabel="Pilih tanggal dan jam"
        >
            {({ commit, close }) => (
                <div className="space-y-2">
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <DayPicker
                            mode="single"
                            required
                            autoFocus
                            locale={calendarLocale}
                            selected={isoToLocalDate(parts?.day) ?? undefined}
                            onSelect={(day) =>
                                commit(joinDayTime(localDateToIso(day), parts?.time ?? '00:00'))
                            }
                            defaultMonth={isoToLocalDate(parts?.day ?? today) ?? undefined}
                            today={isoToLocalDate(today) ?? undefined}
                            modifiersClassNames={SELECTED_DAY_CLASSES}
                            navLayout="around"
                        />
                        <TimeSlotList
                            value={parts?.time ?? ''}
                            onPick={(slot) => {
                                commit(joinDayTime(parts?.day ?? today, slot));
                                close();
                            }}
                            className="max-h-44 grid-cols-4 border-t pt-3 sm:max-h-80 sm:w-28 sm:grid-cols-1 sm:border-t-0 sm:border-l sm:pt-0 sm:pl-3"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        className="w-full"
                        onClick={close}
                    >
                        Selesai
                    </Button>
                </div>
            )}
        </PickerField>
    );
}
