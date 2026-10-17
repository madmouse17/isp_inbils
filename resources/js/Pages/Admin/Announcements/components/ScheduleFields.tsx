import { DateTimePicker } from '@/Components/ui';

interface ScheduleFieldsProps {
    idPrefix: string;
    /** `Y-m-d H:i` on the company clock. */
    startsAt: string;
    onStartsAtChange: (value: string) => void;
    /** `''` for no end. */
    endsAt: string;
    onEndsAtChange: (value: string) => void;
    errors: { starts_at?: string; ends_at?: string };
}

/** When the row shows in the app. A start in the future makes it Terjadwal; no end keeps it up until it is deactivated. */
export function ScheduleFields({
    idPrefix,
    startsAt,
    onStartsAtChange,
    endsAt,
    onEndsAtChange,
    errors,
}: ScheduleFieldsProps) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <DateTimePicker
                id={`${idPrefix}-starts-at`}
                label="Mulai tayang"
                value={startsAt}
                onChange={onStartsAtChange}
                error={errors.starts_at}
                required
            />
            <DateTimePicker
                id={`${idPrefix}-ends-at`}
                label="Selesai tayang"
                value={endsAt}
                onChange={onEndsAtChange}
                error={errors.ends_at}
                hint="Kosongkan agar tayang sampai dinonaktifkan."
            />
        </div>
    );
}
