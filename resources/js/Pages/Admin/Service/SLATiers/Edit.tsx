import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Switch } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { CalendarField, type SlaCalendar } from './components/CalendarField';

interface SlaData {
    id: string;
    name: string;
    response_time_hours: number;
    resolution_time_hours: number;
    calendar: SlaCalendar;
    is_active: boolean;
}

interface EditProps extends Record<string, unknown> {
    slaTier: { data: SlaData };
    /** Whether the company has set its service hours. */
    serviceHoursSet: boolean;
}

export default function Edit({ slaTier, serviceHoursSet }: EditProps) {
    const s = slaTier.data;
    const { data, setData, put, processing, errors } = useForm<{
        name: string;
        response_time_hours: string;
        resolution_time_hours: string;
        calendar: SlaCalendar;
        is_active: boolean;
    }>({
        name: s.name,
        response_time_hours: String(s.response_time_hours),
        resolution_time_hours: String(s.resolution_time_hours),
        calendar: s.calendar,
        is_active: s.is_active,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('admin.sla-tiers.update', s.id));
    };

    return (
        <AdminLayout title="Ubah Tingkat SLA">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah Tingkat SLA"
                    subtitle="Target layanan per paket. Hasil diukur otomatis dari tiket."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.sla-tiers.index')}>Kembali</Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Target layanan</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Nama"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            <Input
                                label="Target respons (jam)"
                                type="number"
                                min={1}
                                max={720}
                                value={data.response_time_hours}
                                onChange={(e) => setData('response_time_hours', e.target.value)}
                                error={errors.response_time_hours}
                                hint="Batas waktu sampai teknisi menekan Mulai pada tiket."
                                required
                            />
                            <Input
                                label="Target penyelesaian (jam)"
                                type="number"
                                min={1}
                                max={720}
                                value={data.resolution_time_hours}
                                onChange={(e) => setData('resolution_time_hours', e.target.value)}
                                error={errors.resolution_time_hours}
                                hint="Batas waktu sampai tiket diselesaikan."
                                required
                            />
                            <div className="flex items-end">
                                <Switch
                                    label="Aktif"
                                    checked={data.is_active}
                                    onCheckedChange={(c) => setData('is_active', c)}
                                />
                            </div>
                            <CalendarField
                                value={data.calendar}
                                onChange={(calendar) => setData('calendar', calendar)}
                                error={errors.calendar}
                                serviceHoursSet={serviceHoursSet}
                            />
                            <p className="text-sm text-muted-foreground md:col-span-2">
                                Tiket berprioritas mendesak memakai setengah dari kedua target
                                (dibulatkan ke atas). Perubahan target dan hitungan hanya berlaku
                                untuk tiket baru.
                            </p>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.sla-tiers.index'))}
                        >
                            Batal
                        </Button>
                        <Button type="submit" loading={processing}>
                            Simpan
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
