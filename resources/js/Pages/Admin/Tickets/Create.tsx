import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Clock } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    NativeSelect,
    SearchSelect,
    Textarea,
} from '@/Components/ui';
import { PageHeader, RemoteSearchSelect } from '@/Components/composite';
import type { RemoteOption } from '@/Components/composite';
import { useCompany } from '@/hooks/useCompany';
import { formatDateTime } from '@/lib/format';
import { optionsOf, PRIORITY, TICKET_SOURCE } from '@/lib/statuses';
import type { TicketSlaPreview } from '@/types/ticketing';

interface CreateProps extends Record<string, unknown> {
    categories: { data: { id: string; name: string; default_priority?: string }[] };
    locations: { data: { id: string; name: string; code: string }[] };
    canChooseLocation: boolean;
    /** Sent only after a search: `customer_search`, `subscription_search` and `networkAsset_search` ask for them. */
    customerOptions?: RemoteOption[];
    subscriptionOptions?: RemoteOption[];
    networkAssetOptions?: RemoteOption[];
}

export default function Create({
    categories,
    locations,
    canChooseLocation,
    customerOptions,
    subscriptionOptions,
    networkAssetOptions,
}: CreateProps) {
    const company = useCompany();
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        source: 'customer',
        category_id: '',
        priority: 'medium',
        customer_id: '',
        subscription_id: '',
        network_asset_id: '',
        location_id: '',
    });
    // The category's default priority fills the field until the person picks one.
    const [priorityPicked, setPriorityPicked] = useState(false);
    // The category's name fills the title until the person writes their own.
    const [titleEdited, setTitleEdited] = useState(false);
    // The subscription the page picked itself (the customer has only one), which the picker shows before anything is searched.
    const [pickedSubscription, setPickedSubscription] = useState<RemoteOption | null>(null);
    const [preview, setPreview] = useState<TicketSlaPreview | null>(null);
    const previewRequest = useRef(0);
    const selectedCategory = categories.data.find((c) => String(c.id) === data.category_id);

    // What the ticket would be held to, from the category, subscription and priority picked so far. A newer question makes an
    // older answer stale: it is ignored, never aborted (an aborted request counts as a failed one in the e2e watcher).
    useEffect(() => {
        if (!data.category_id) return;

        const asked = ++previewRequest.current;
        axios
            .get<TicketSlaPreview>(route('admin.tickets.sla-preview'), {
                params: {
                    category_id: data.category_id,
                    subscription_id: data.subscription_id || undefined,
                    priority: data.priority,
                },
            })
            .then((response) => {
                if (previewRequest.current === asked) setPreview(response.data);
            })
            .catch(() => {
                if (previewRequest.current === asked) setPreview(null);
            });
    }, [data.category_id, data.subscription_id, data.priority]);

    const pickCategory = (value: string) => {
        const category = categories.data.find((c) => String(c.id) === value);
        const priority = category?.default_priority;

        setData((previous) => ({
            ...previous,
            category_id: value,
            title: !titleEdited && category ? category.name : previous.title,
            priority:
                !priorityPicked && priority && priority in PRIORITY ? priority : previous.priority,
        }));
    };

    // The server offers only the subscriptions and assets of what is picked, so a mismatch is never offered.
    const narrowOptions = (customerId: string, subscriptionId: string, only: string[]) =>
        router.get(
            route('admin.tickets.create'),
            { customer_id: customerId, subscription_id: subscriptionId },
            { only, preserveState: true, preserveScroll: true, replace: true },
        );

    const pickCustomer = (value: string) => {
        setData((previous) => ({
            ...previous,
            customer_id: value,
            subscription_id: '',
            network_asset_id: '',
        }));
        setPickedSubscription(null);

        if (value === '') {
            narrowOptions(value, '', ['subscriptionOptions', 'networkAssetOptions']);

            return;
        }

        router.get(
            route('admin.tickets.create'),
            // `subscription_recent` asks for the customer's newest subscriptions: a customer who has only one gets it picked.
            { customer_id: value, subscription_id: '', subscription_recent: 1 },
            {
                only: ['subscriptionOptions', 'networkAssetOptions'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: (page) => {
                    const offered = page.props.subscriptionOptions as RemoteOption[] | undefined;
                    if (offered?.length !== 1) return;

                    setData((previous) => ({ ...previous, subscription_id: offered[0].value }));
                    setPickedSubscription(offered[0]);
                    narrowOptions(value, offered[0].value, ['networkAssetOptions']);
                },
            },
        );
    };

    const pickSubscription = (value: string) => {
        setData((previous) => ({ ...previous, subscription_id: value, network_asset_id: '' }));
        narrowOptions(data.customer_id, value, ['networkAssetOptions']);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.tickets.store'));
    };

    // The line belongs to the category now picked; with none picked an answer of an earlier pick is not shown.
    const target = data.category_id ? preview : null;

    return (
        <AdminLayout title="Buat Tiket">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Tiket"
                    subtitle="Catat keluhan atau gangguan, lalu simpan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.tickets.index')}>Kembali</Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data tiket</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Judul"
                                value={data.title}
                                onChange={(e) => {
                                    setTitleEdited(true);
                                    setData('title', e.target.value);
                                }}
                                error={errors.title}
                                required
                            />
                            <NativeSelect
                                label="Sumber"
                                value={data.source}
                                onChange={(e) => setData('source', e.target.value)}
                                options={optionsOf(TICKET_SOURCE)}
                                error={errors.source}
                                required
                            />
                            <SearchSelect
                                label="Kategori"
                                value={data.category_id}
                                onChange={pickCategory}
                                options={categories.data.map((c) => ({
                                    value: String(c.id),
                                    label: c.name,
                                }))}
                                placeholder="Cari kategori"
                                error={errors.category_id}
                                required
                            />
                            <NativeSelect
                                label="Prioritas"
                                value={data.priority}
                                onChange={(e) => {
                                    setPriorityPicked(true);
                                    setData('priority', e.target.value);
                                }}
                                options={optionsOf(PRIORITY)}
                                error={errors.priority}
                                hint={
                                    !priorityPicked && selectedCategory
                                        ? `Bawaan kategori ${selectedCategory.name}.`
                                        : undefined
                                }
                            />
                            <RemoteSearchSelect
                                field="customer"
                                label="Pelanggan"
                                options={customerOptions}
                                value={data.customer_id}
                                onChange={pickCustomer}
                                placeholder="Cari nama atau kode pelanggan"
                                error={errors.customer_id}
                            />
                            <RemoteSearchSelect
                                field="subscription"
                                label="Langganan"
                                options={subscriptionOptions}
                                selected={pickedSubscription}
                                value={data.subscription_id}
                                onChange={pickSubscription}
                                placeholder="Cari kode langganan"
                                error={errors.subscription_id}
                            />
                            <RemoteSearchSelect
                                field="networkAsset"
                                label="Aset jaringan"
                                options={networkAssetOptions}
                                value={data.network_asset_id}
                                onChange={(value) => setData('network_asset_id', value)}
                                placeholder="Cari kode atau nama aset"
                                error={errors.network_asset_id}
                            />
                            {canChooseLocation && (
                                <SearchSelect
                                    label="Lokasi"
                                    value={data.location_id}
                                    onChange={(value) => setData('location_id', value)}
                                    options={locations.data.map((l) => ({
                                        value: String(l.id),
                                        label: `${l.code} - ${l.name}`,
                                    }))}
                                    placeholder="Cari kode atau nama lokasi"
                                    error={errors.location_id}
                                />
                            )}
                            <Textarea
                                className="md:col-span-2"
                                label="Deskripsi"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                error={errors.description}
                                rows={3}
                            />
                            {target && (
                                <p
                                    role="status"
                                    className="flex items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-foreground md:col-span-2"
                                >
                                    <Clock
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span>
                                        {target.response_due
                                            ? `Target respons ${formatDateTime(target.response_due, company)}`
                                            : 'Tanpa target respons'}
                                        {`, selesai ${formatDateTime(target.resolution_due, company)}`}
                                    </span>
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.tickets.index'))}
                        >
                            Batal
                        </Button>
                        <Button type="submit" variant="primary" loading={processing}>
                            Simpan
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
