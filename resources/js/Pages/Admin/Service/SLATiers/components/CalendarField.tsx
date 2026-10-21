import { Link } from '@inertiajs/react';
import { Alert, AlertDescription, NativeSelect } from '@/Components/ui';
import { usePermission } from '@/hooks/usePermission';

/** How a tier counts the hours of its targets: every hour, or only the open hours of the company (K11). */
export type SlaCalendar = '24h' | 'business';

export const SLA_CALENDAR_LABEL: Record<SlaCalendar, string> = {
    '24h': '24 jam',
    business: 'Jam layanan',
};

const OPTIONS = (Object.keys(SLA_CALENDAR_LABEL) as SlaCalendar[]).map((value) => ({
    value,
    label: SLA_CALENDAR_LABEL[value],
}));

interface CalendarFieldProps {
    value: SlaCalendar;
    onChange: (value: SlaCalendar) => void;
    error?: string;
    /** Whether the company has set its service hours; a tier on them counts 24 hours until it has. */
    serviceHoursSet: boolean;
}

export function CalendarField({ value, onChange, error, serviceHoursSet }: CalendarFieldProps) {
    const canSetHours = usePermission().can('company.manage');

    return (
        <div className="space-y-3 md:col-span-2">
            <NativeSelect
                label="Hitungan tenggat"
                value={value}
                onChange={(e) => onChange(e.target.value as SlaCalendar)}
                options={OPTIONS}
                error={error}
                hint="24 jam menghitung setiap jam, siang dan malam. Jam layanan hanya menghitung jam buka perusahaan."
            />
            {value === 'business' && !serviceHoursSet && (
                <Alert variant="warning">
                    <AlertDescription>
                        Jam layanan belum diisi; tenggat dihitung 24 jam.{' '}
                        {canSetHours ? (
                            <Link
                                href={route('admin.company.settings.edit')}
                                className="font-medium underline underline-offset-2"
                            >
                                Isi di Pengaturan Perusahaan
                            </Link>
                        ) : (
                            'Minta admin mengisinya di Pengaturan Perusahaan.'
                        )}
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}
