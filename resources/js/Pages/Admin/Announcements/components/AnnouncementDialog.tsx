import { useState } from 'react';
import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { NativeSelect } from '@/Components/composite';
import { PhonePreview } from '@/Components/composite/PhonePreview';
import { Button, Input, Textarea } from '@/Components/ui';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import { useCompany } from '@/hooks/useCompany';
import { fieldError, nowInZone } from '@/lib/announcements';
import { phpToDateFns } from '@/lib/dateFormat';
import { formatDayTime } from '@/lib/dateValue';
import { formatNumber } from '@/lib/format';
import { ANNOUNCEMENT_KIND, labelOf } from '@/lib/statuses';
import type { AnnouncementRow, Target } from '../types';
import { EditorDialog, announcementPublishNote, saveNote } from './EditorDialog';
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
    // How many customers publishing reaches, once the server counted them: the confirmation of "Simpan & Terbitkan" is open while it is set.
    const [recipients, setRecipients] = useState<number | null>(null);
    const [counting, setCounting] = useState(false);
    const [countError, setCountError] = useState<string | undefined>();
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
    // A published row cannot be published again: only a draft (or a new one) has the combined button.
    const canPublishHere = announcement === undefined || announcement.status === 'draft';

    const targets = () => {
        const targeted = data.audience === 'targeted';

        return {
            location_ids: targeted ? locations.map((item) => item.id) : [],
            service_package_category_ids: targeted ? categories.map((item) => item.id) : [],
        };
    };

    /** Saves the form. `publish` also publishes it in the same step: when the server refuses that, nothing is saved. */
    const send = (publish: boolean) => {
        form.transform((values) => ({
            ...values,
            ...targets(),
            ...(publish ? { publish: true } : {}),
        }));
        const options = {
            preserveScroll: true,
            onSuccess: onClose,
            onError: () => setRecipients(null),
        };

        if (announcement) {
            form.put(route('admin.announcements.update', announcement.id), options);
        } else {
            form.post(route('admin.announcements.store'), options);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(false);
    };

    /** The confirmation names how many customers publishing reaches, so the server counts them for this targeting first. */
    const askToPublish = async () => {
        const chosen = targets();

        // Nothing is targeted yet: the server's own refusal says what is missing, under the field.
        if (
            data.audience === 'targeted' &&
            chosen.location_ids.length + chosen.service_package_category_ids.length === 0
        ) {
            send(true);

            return;
        }

        setCounting(true);
        setCountError(undefined);

        try {
            const { data: answer } = await axios.post<{ count: number }>(
                route('admin.announcements.audience'),
                { audience: data.audience, ...chosen },
            );
            setRecipients(answer.count);
        } catch {
            setCountError('Jumlah penerima gagal dihitung. Coba lagi.');
        } finally {
            setCounting(false);
        }
    };

    const start = formatDayTime(data.starts_at, dateFormat);
    const end = formatDayTime(data.ends_at, dateFormat);
    const notifies = data.kind === 'outage' || data.kind === 'maintenance';

    return (
        <>
            <EditorDialog
                title={announcement ? 'Ubah Pengumuman' : 'Buat Pengumuman'}
                description={saveNote(announcement?.status, true)}
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
                    {countError ? (
                        <p role="alert" className="text-sm text-destructive-text">
                            {countError}
                        </p>
                    ) : null}
                    <div className="flex flex-wrap justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Batal
                        </Button>
                        <Button type="submit" variant="primary" loading={processing}>
                            Simpan
                        </Button>
                        {canPublishHere ? (
                            <Button
                                type="button"
                                variant="outline"
                                loading={counting}
                                disabled={processing}
                                onClick={() => void askToPublish()}
                            >
                                Simpan & Terbitkan
                            </Button>
                        ) : null}
                    </div>
                </form>
            </EditorDialog>
            {recipients !== null ? (
                <AlertDialog
                    open
                    onOpenChange={(open) => {
                        if (!open && !processing) setRecipients(null);
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                {`Terbitkan untuk ${formatNumber(recipients)} pelanggan?`}
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                {`${data.title.trim() === '' ? '' : `${data.title}: `}${announcementPublishNote(data.kind)} Setelah terbit, pengumuman tidak bisa dihapus, hanya dinonaktifkan.`}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={processing}>
                                Kembali ke formulir
                            </AlertDialogCancel>
                            <Button
                                type="button"
                                variant="primary"
                                loading={processing}
                                onClick={() => send(true)}
                            >
                                Simpan & Terbitkan
                            </Button>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            ) : null}
        </>
    );
}
