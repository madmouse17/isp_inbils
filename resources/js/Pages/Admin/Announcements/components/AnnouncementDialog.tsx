import { useState } from 'react';
import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { NativeSelect } from '@/Components/composite';
import { PhonePreview } from '@/Components/composite/PhonePreview';
import { Button, Input, Textarea } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { fieldError, nowInZone } from '@/lib/announcements';
import { phpToDateFns } from '@/lib/dateFormat';
import { formatDayTime } from '@/lib/dateValue';
import { ANNOUNCEMENT_KIND, labelOf } from '@/lib/statuses';
import type { AnnouncementRow, Target } from '../types';
import { EditorDialog, saveNote } from './EditorDialog';
import { ScheduleFields } from './ScheduleFields';
import { TargetingFields } from './TargetingFields';
import type { TargetingChoices } from './TargetingFields';

const TITLE_MAX = 120;
const BODY_MAX = 2000;

interface AnnouncementDialogProps {
    /** The announcement being edited. Omit to write a new one. */
    announcement?: AnnouncementRow;
    kinds: string[];
    choices: TargetingChoices;
    onClose: () => void;
}

/** Mounted only while open, so each opening starts from the announcement (or an empty form) with no stale errors. */
export function AnnouncementDialog({
    announcement,
    kinds,
    choices,
    onClose,
}: AnnouncementDialogProps) {
    const company = useCompany();
    const dateFormat = phpToDateFns(company?.settings?.date_format);
    const [defaultStart] = useState(() => nowInZone(company?.timezone));
    const [locations, setLocations] = useState<Target[]>(announcement?.location_targets ?? []);
    const [categories, setCategories] = useState<Target[]>(
        announcement?.package_category_targets ?? [],
    );
    const form = useForm({
        title: announcement?.title ?? '',
        body: announcement?.body ?? '',
        kind: announcement?.kind ?? 'info',
        audience: announcement?.audience ?? 'all',
        starts_at: announcement?.starts_at ?? defaultStart,
        ends_at: announcement?.ends_at ?? '',
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const targeted = data.audience === 'targeted';
        form.transform((values) => ({
            ...values,
            location_ids: targeted ? locations.map((item) => item.id) : [],
            service_package_category_ids: targeted ? categories.map((item) => item.id) : [],
        }));
        const options = { preserveScroll: true, onSuccess: onClose };

        if (announcement) {
            form.put(route('admin.announcements.update', announcement.id), options);
        } else {
            form.post(route('admin.announcements.store'), options);
        }
    };

    const start = formatDayTime(data.starts_at, dateFormat);
    const end = formatDayTime(data.ends_at, dateFormat);
    const notifies = data.kind === 'outage' || data.kind === 'maintenance';

    return (
        <EditorDialog
            title={announcement ? 'Ubah Pengumuman' : 'Buat Pengumuman'}
            description={saveNote(announcement?.status)}
            onClose={onClose}
            preview={
                <PhonePreview
                    announcement={{
                        kind: data.kind,
                        title: data.title,
                        body: data.body,
                        period: start && (end ? `${start} sampai ${end}` : `Mulai ${start}`),
                    }}
                />
            }
        >
            <form onSubmit={submit} noValidate className="space-y-4">
                <NativeSelect
                    id="announcement-kind"
                    label="Jenis"
                    value={data.kind}
                    onChange={(event) => setData('kind', event.target.value)}
                    options={kinds.map((value) => ({
                        value,
                        label: labelOf(ANNOUNCEMENT_KIND, value),
                    }))}
                    hint={
                        notifies
                            ? 'Tampil paling atas di beranda aplikasi dan mengirim notifikasi ke pelanggan sasaran.'
                            : 'Tampil di beranda aplikasi pelanggan sasaran.'
                    }
                    error={errors.kind}
                />
                <Input
                    id="announcement-title"
                    label="Judul"
                    value={data.title}
                    onChange={(event) => setData('title', event.target.value)}
                    maxLength={TITLE_MAX}
                    hint={`${data.title.length}/${TITLE_MAX} karakter.`}
                    error={errors.title}
                    required
                />
                <Textarea
                    id="announcement-body"
                    label="Isi"
                    rows={5}
                    value={data.body}
                    onChange={(event) => setData('body', event.target.value)}
                    maxLength={BODY_MAX}
                    hint={`Teks biasa, baris baru ikut tampil. ${data.body.length}/${BODY_MAX} karakter.`}
                    error={errors.body}
                    required
                />
                <TargetingFields
                    idPrefix="announcement"
                    choices={choices}
                    audience={data.audience}
                    onAudienceChange={(audience) => setData('audience', audience)}
                    locations={locations}
                    onLocationsChange={setLocations}
                    categories={categories}
                    onCategoriesChange={setCategories}
                    errors={{
                        audience: errors.audience,
                        locations: fieldError(errors, 'location_ids'),
                        categories: fieldError(errors, 'service_package_category_ids'),
                    }}
                />
                <ScheduleFields
                    idPrefix="announcement"
                    startsAt={data.starts_at}
                    onStartsAtChange={(value) => setData('starts_at', value)}
                    endsAt={data.ends_at}
                    onEndsAtChange={(value) => setData('ends_at', value)}
                    errors={errors}
                />
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" variant="primary" loading={processing}>
                        Simpan
                    </Button>
                </div>
            </form>
        </EditorDialog>
    );
}
