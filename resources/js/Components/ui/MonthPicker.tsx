import { useEffect, useRef, useState } from 'react';
import { CalendarRange, ChevronLeft, ChevronRight } from 'lucide-react';
import { useCompany } from '@/hooks/useCompany';
import { MAX_YEAR, MIN_YEAR, todayInZone } from '@/lib/dateRange';
import { MONTH_NAMES, formatMonth, monthToIso, parseMonth } from '@/lib/dateValue';
import { IconButton } from './IconButton';
import { PickerField } from './PickerField';
import type { PickerFieldShared } from './PickerField';
import { moveFocus, pickerCellClass } from './pickerCell';

export interface MonthPickerProps extends PickerFieldShared {
    /** ISO `yyyy-mm`; blank or null for no month. */
    value: string | null | undefined;
    /** Called with `yyyy-mm`, or `''` when cleared. Never with a half-typed one. */
    onChange: (value: string) => void;
}

interface MonthGridProps {
    selected: string;
    currentMonth: string;
    onPick: (month: string) => void;
}

/** A year with its twelve months: arrow keys move between months, the buttons above change the year. */
function MonthGrid({ selected, currentMonth, onPick }: MonthGridProps) {
    const [year, setYear] = useState(Number((selected || currentMonth).slice(0, 4)));
    const gridRef = useRef<HTMLDivElement>(null);
    // The tab stop: the chosen month, else this month, else January of the year on show.
    const stop = [selected, currentMonth].find((month) => month.startsWith(`${year}-`));

    useEffect(() => {
        // Only when the popover opens; changing the year keeps focus on the year buttons.
        gridRef.current?.querySelector<HTMLElement>('[data-active]')?.focus();
    }, []);

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
                <IconButton
                    type="button"
                    size="sm"
                    label="Tahun sebelumnya"
                    disabled={year <= MIN_YEAR}
                    onClick={() => setYear(year - 1)}
                >
                    <ChevronLeft className="h-4 w-4" />
                </IconButton>
                <span aria-live="polite" className="text-sm font-semibold">
                    {year}
                </span>
                <IconButton
                    type="button"
                    size="sm"
                    label="Tahun berikutnya"
                    disabled={year >= MAX_YEAR}
                    onClick={() => setYear(year + 1)}
                >
                    <ChevronRight className="h-4 w-4" />
                </IconButton>
            </div>
            <div
                ref={gridRef}
                role="group"
                aria-label={`Bulan, ${year}`}
                onKeyDown={(event) =>
                    moveFocus(event, {
                        ArrowRight: 1,
                        ArrowLeft: -1,
                        ArrowDown: 3,
                        ArrowUp: -3,
                        Home: -Infinity,
                        End: Infinity,
                    })
                }
                className="grid grid-cols-3 gap-1"
            >
                {MONTH_NAMES.map((name, index) => {
                    const month = monthToIso(year, index + 1);
                    const isStop = stop ? month === stop : index === 0;

                    return (
                        <button
                            key={month}
                            type="button"
                            data-picker-item
                            data-active={isStop ? '' : undefined}
                            tabIndex={isStop ? 0 : -1}
                            aria-label={`${name} ${year}`}
                            aria-pressed={month === selected}
                            aria-current={month === currentMonth ? 'date' : undefined}
                            onClick={() => onPick(month)}
                            className={pickerCellClass(month === selected, month === currentMonth)}
                        >
                            {name}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * One month you can type (`Oktober 2026`, `10/2026`, `2026-10`) or pick from a year with twelve
 * months. The value is the ISO `yyyy-mm`, whatever the company writes its days like.
 */
export function MonthPicker({
    value,
    onChange,
    placeholder = 'Pilih bulan',
    ...field
}: MonthPickerProps) {
    const company = useCompany();
    const currentMonth = todayInZone(company?.timezone).slice(0, 7);
    // A value that is not a real month shows as empty, like the other pickers.
    const month = formatMonth(value) ? (value ?? '') : '';

    return (
        <PickerField
            {...field}
            placeholder={placeholder}
            value={month}
            onChange={onChange}
            format={formatMonth}
            parse={(text) => {
                if (text.trim() === '') return { ok: true, value: '' };

                const parsed = parseMonth(text);

                return parsed
                    ? { ok: true, value: parsed }
                    : {
                          ok: false,
                          message: `Format bulan tidak valid. Contoh: ${formatMonth(currentMonth)}.`,
                      };
            }}
            icon={<CalendarRange className="h-4 w-4" />}
            fallbackLabel="Bulan"
            openLabel="Buka pilihan bulan"
            clearLabel="Hapus bulan"
            popoverLabel="Pilih bulan"
            popoverClassName="w-72"
        >
            {({ commit, close }) => (
                <MonthGrid
                    selected={month}
                    currentMonth={currentMonth}
                    onPick={(picked) => {
                        commit(picked);
                        close();
                    }}
                />
            )}
        </PickerField>
    );
}
