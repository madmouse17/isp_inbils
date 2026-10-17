import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { NativeSelect } from '@/Components/composite';
import { PhonePreview } from '@/Components/composite/PhonePreview';
import type { PhoneBanner } from '@/Components/composite/PhonePreview';
import { Alert, AlertDescription, Button, FileUpload, Input, Progress } from '@/Components/ui';
import {
    fieldError,
    isBannerRatio,
    nowInZone,
    placeInCarousel,
    readImageSize,
} from '@/lib/announcements';
import { useCompany } from '@/hooks/useCompany';
import { BANNER_APP_ROUTE, BANNER_LINK_KIND, labelOf, optionsOf } from '@/lib/statuses';
import type { BannerRow, CarouselPreview, Target } from '../types';
import { EditorDialog, saveNote } from './EditorDialog';
import { ScheduleFields } from './ScheduleFields';
import { TargetingFields } from './TargetingFields';
import type { TargetingChoices } from './TargetingFields';

const TITLE_MAX = 80;
const ALT_MAX = 200;
const LINK_MAX = 500;

type Slide = PhoneBanner & { id: string | null; sortOrder: number };

interface BannerDialogProps {
    /** The banner being edited. Omit to add a new one. */
    banner?: BannerRow;
    appRoutes: string[];
    choices: TargetingChoices;
    preview: CarouselPreview;
    onClose: () => void;
}

/**
 * Mounted only while open. The image is checked in the browser as soon as it is chosen (2:1 within
 * 5 percent, the rule the server repeats), and the phone shows the banner among the live ones.
 */
export function BannerDialog({ banner, appRoutes, choices, preview, onClose }: BannerDialogProps) {
    const company = useCompany();
    const [defaultStart] = useState(() => nowInZone(company?.timezone));
    const [locations, setLocations] = useState<Target[]>(banner?.location_targets ?? []);
    const [categories, setCategories] = useState<Target[]>(banner?.package_category_targets ?? []);
    const form = useForm({
        title: banner?.title ?? '',
        alt_text: banner?.alt_text ?? '',
        image: null as File | null,
        link_kind: banner?.link_kind ?? 'none',
        link_target: banner?.link_target ?? '',
        audience: banner?.audience ?? 'all',
        sort_order: String(banner?.sort_order ?? 0),
        starts_at: banner?.starts_at ?? defaultStart,
        ends_at: banner?.ends_at ?? '',
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;

    // The file the person chose last: an answer about an earlier one is dropped.
    const chosen = useRef<File | null>(null);
    const [imageUrl, setImageUrl] = useState<string | null>(null);
    const [imageError, setImageError] = useState<string | null>(null);

    // A new address replaces the last one, which is let go here, and the open one is let go on close.
    useEffect(
        () => () => {
            if (imageUrl) URL.revokeObjectURL(imageUrl);
        },
        [imageUrl],
    );

    const refuse = (message: string) => {
        chosen.current = null;
        setData('image', null);
        setImageUrl(null);
        setImageError(message);
    };

    const pickImage = (file: File | null) => {
        const before = chosen.current;
        chosen.current = file;
        setData('image', file);
        setImageUrl(file ? URL.createObjectURL(file) : null);

        if (file === null) {
            // Clearing a file the person had chosen clears its message; the field clears itself
            // after a refusal, and that must not wipe the reason.
            if (before !== null) setImageError(null);

            return;
        }

        setImageError(null);
        readImageSize(file).then(
            ({ width, height }) => {
                if (chosen.current === file && !isBannerRatio(width, height)) {
                    refuse(
                        `Gambar ini ${width} x ${height} piksel. Rasio harus 2:1 dengan selisih paling banyak 5%, misalnya 1200 x 600.`,
                    );
                }
            },
            () => {
                if (chosen.current === file) refuse('Berkas ini tidak bisa dibaca sebagai gambar.');
            },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const targeted = data.audience === 'targeted';
        form.transform(({ image, ...values }) => ({
            ...values,
            // An empty file field would be read by the server as an image that is not a file.
            ...(image ? { image } : {}),
            ...(banner ? { _method: 'put' } : {}),
            link_target: values.link_kind === 'none' ? '' : values.link_target,
            location_ids: targeted ? locations.map((item) => item.id) : [],
            service_package_category_ids: targeted ? categories.map((item) => item.id) : [],
        }));

        form.post(
            banner
                ? route('admin.promo-banners.update', banner.id)
                : route('admin.promo-banners.store'),
            { forceFormData: true, preserveScroll: true, onSuccess: onClose },
        );
    };

    const draft: Slide = {
        id: banner?.id ?? null,
        sortOrder: Number.parseInt(data.sort_order, 10) || 0,
        title: data.title,
        altText: data.alt_text,
        imageUrl: imageUrl ?? banner?.image_url ?? null,
    };
    const live: Slide[] = preview.banners.data
        .filter((item) => item.id !== banner?.id)
        .map((item) => ({
            id: item.id,
            sortOrder: item.sort_order,
            title: item.title,
            altText: item.alt_text,
            imageUrl: item.image_url,
        }));
    const { banners: slides, index } = placeInCarousel(live, draft, preview.max_banners);

    return (
        <EditorDialog
            title={banner ? 'Ubah Banner Promo' : 'Buat Banner Promo'}
            description={saveNote(banner?.status)}
            onClose={onClose}
            preview={
                <div className="space-y-3">
                    <PhonePreview
                        banners={index === null ? [draft] : slides}
                        bannerIndex={index ?? 0}
                    />
                    <div className="mx-auto space-y-2 max-w-90 text-sm text-muted-foreground">
                        <p>
                            Banner ini tampil bersama banner lain yang sedang tayang, urut seperti
                            di aplikasi. Aplikasi menampilkan paling banyak {preview.max_banners}{' '}
                            banner dan berganti tiap {preview.auto_advance_seconds} detik.
                        </p>
                        {index === null ? (
                            <Alert variant="warning">
                                <AlertDescription>
                                    Dengan urutan ini banner tidak masuk {preview.max_banners}{' '}
                                    banner pertama, jadi belum tampil di aplikasi. Pratinjau hanya
                                    menunjukkan banner ini.
                                </AlertDescription>
                            </Alert>
                        ) : null}
                    </div>
                </div>
            }
        >
            <form onSubmit={submit} noValidate className="space-y-4">
                <FileUpload
                    label="Gambar"
                    value={data.image}
                    onChange={pickImage}
                    acceptedFileTypes={['image/jpeg', 'image/png', 'image/webp']}
                    maxFileSize="2MB"
                    previewUrl={banner?.image_url}
                    previewName={banner ? `Gambar banner ${banner.title}` : undefined}
                    hint="JPG, PNG, atau WebP, maksimal 2 MB, rasio 2:1 (disarankan 1200 x 600 piksel)."
                    error={imageError ?? errors.image}
                    required={!banner}
                />
                {processing && data.image ? (
                    <Progress label="Mengunggah gambar" value={form.progress?.percentage ?? 0} />
                ) : null}
                <Input
                    id="banner-title"
                    label="Judul"
                    value={data.title}
                    onChange={(event) => setData('title', event.target.value)}
                    maxLength={TITLE_MAX}
                    hint={`Tampil sebagai keterangan di bawah gambar. ${data.title.length}/${TITLE_MAX} karakter.`}
                    error={errors.title}
                    required
                />
                <Input
                    id="banner-alt-text"
                    label="Teks alternatif"
                    value={data.alt_text}
                    onChange={(event) => setData('alt_text', event.target.value)}
                    maxLength={ALT_MAX}
                    hint={`Dibacakan pembaca layar. Jelaskan isi gambar. ${data.alt_text.length}/${ALT_MAX} karakter.`}
                    error={errors.alt_text}
                    required
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <NativeSelect
                        id="banner-link-kind"
                        label="Saat banner diketuk"
                        value={data.link_kind}
                        onChange={(event) => {
                            setData((values) => ({
                                ...values,
                                link_kind: event.target.value,
                                link_target: '',
                            }));
                        }}
                        options={optionsOf(BANNER_LINK_KIND)}
                        error={errors.link_kind}
                    />
                    {data.link_kind === 'app' ? (
                        <NativeSelect
                            id="banner-link-app"
                            label="Halaman di aplikasi"
                            value={data.link_target}
                            onChange={(event) => setData('link_target', event.target.value)}
                            options={[
                                { value: '', label: 'Pilih halaman' },
                                ...appRoutes.map((value) => ({
                                    value,
                                    label: labelOf(BANNER_APP_ROUTE, value),
                                })),
                            ]}
                            error={errors.link_target}
                            required
                        />
                    ) : null}
                    {data.link_kind === 'url' ? (
                        <Input
                            id="banner-link-url"
                            label="Alamat tautan"
                            inputMode="url"
                            placeholder="https://"
                            value={data.link_target}
                            onChange={(event) => setData('link_target', event.target.value)}
                            maxLength={LINK_MAX}
                            hint="Harus diawali https://. Dibuka di peramban ponsel."
                            error={errors.link_target}
                            required
                        />
                    ) : null}
                </div>
                <Input
                    id="banner-sort-order"
                    label="Urutan"
                    type="number"
                    min={0}
                    max={65535}
                    value={data.sort_order}
                    onChange={(event) => setData('sort_order', event.target.value)}
                    hint="Angka kecil tampil lebih dulu."
                    error={errors.sort_order}
                />
                <TargetingFields
                    idPrefix="banner"
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
                    idPrefix="banner"
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
