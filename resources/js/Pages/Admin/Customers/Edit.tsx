import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    Button,
    Card,
    CardContent,
    Checkbox,
    Tab,
    TabList,
    TabPanel,
    Tabs,
    type GeocodeResult,
} from '@/Components/ui';
import {
    ConflictBanner,
    CustomerAcquirerField,
    CustomerAddressEditor,
    CustomerContactEditor,
    CustomerDataFields,
    PageHeader,
    type CodeSequence,
    type CustomerDataValue,
    type IndonesiaRegionOptions,
} from '@/Components/composite';
import { formatDate } from '@/lib/format';
import type { PageProps } from '@/types';
import type { AcquirerOption, Customer } from '@/types/models';
import type { CustomerWhatsApp } from '@/types/whatsapp';

interface EditPageProps {
    customer: { data: Customer & { whatsapp: CustomerWhatsApp; updated_at: string } };
    regions: IndonesiaRegionOptions;
    geocodeResults?: GeocodeResult[];
    geocodeIndex?: number;
    acquirerOptions: AcquirerOption[];
    codeSequence: CodeSequence;
    can: {
        update: boolean;
        address: boolean;
        contact: boolean;
        viewAcquirer: boolean;
        assignAcquirer: boolean;
        correctAcquirer: boolean;
    };
}

interface CustomerEditForm extends CustomerDataValue {
    /** Selected acquirer id (`''` = Unassigned); sent as `acquired_by_user_id` only when changed. */
    acquirer_id: string;
    acquirer_correction_reason: string;
    /** Consent to WhatsApp notifications; sent only when changed, see `submit`. */
    whatsapp_opt_in: boolean;
    /** Row version the edit started from; the server refuses the save when the row moved on. */
    expected_updated_at: string;
}

export default function Edit({
    customer,
    regions,
    geocodeResults = [],
    geocodeIndex,
    acquirerOptions,
    can,
    codeSequence,
}: EditPageProps) {
    const c = customer.data;
    const addresses = c.addresses ?? [];
    const contacts = c.contacts ?? [];
    const readOnly = !can.update;
    const [tab, setTab] = useState('customer');

    const initialAcquirerId = c.acquired_by_user_id == null ? '' : String(c.acquired_by_user_id);
    const initialWhatsAppOptIn = c.whatsapp.opted_in;
    const consentStatus = c.whatsapp.opted_in
        ? `Setuju sejak ${formatDate(c.whatsapp.opted_in_at)}`
        : c.whatsapp.opted_out_at
          ? `Berhenti sejak ${formatDate(c.whatsapp.opted_out_at)}`
          : 'Belum ada persetujuan tercatat';

    const { data, setData, put, transform, processing, errors, reset } = useForm<CustomerEditForm>({
        code: c.code ?? '',
        name: c.name ?? '',
        type: (c.type as CustomerDataValue['type']) ?? 'Individual',
        email: c.email ?? '',
        phone: c.phone ?? '',
        tax_id: c.tax_id ?? '',
        contact_person: c.contact_person ?? '',
        notes: c.notes ?? '',
        is_active: Boolean(c.is_active),
        acquirer_id: initialAcquirerId,
        acquirer_correction_reason: '',
        whatsapp_opt_in: initialWhatsAppOptIn,
        expected_updated_at: c.updated_at,
    });
    const conflict = usePage<PageProps>().props.errors.conflict;
    // A conflict reply carries the newer row: keeping the old version makes the retry conflict again until the page is reloaded.
    useEffect(() => {
        if (!conflict) setData('expected_updated_at', c.updated_at);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [c.updated_at]);
    const formErrors = errors as Partial<Record<keyof CustomerDataValue, string>>;
    const acquirerErrors = errors as Partial<
        Record<'acquired_by_user_id' | 'acquirer_correction_reason', string>
    >;
    // Unassigned -> assign needs the assign capability; set -> change/clear is a correction.
    const acquirerEditable =
        can.viewAcquirer &&
        !readOnly &&
        (initialAcquirerId === '' ? can.assignAcquirer : can.correctAcquirer);
    const acquirerChanged = acquirerEditable && data.acquirer_id !== initialAcquirerId;
    const requireReason = acquirerChanged && initialAcquirerId !== '';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (processing || readOnly) return;
        transform(
            ({ acquirer_id, acquirer_correction_reason, whatsapp_opt_in, code, ...values }) => ({
                ...values,
                // A locked code never changes after creation, so it is not sent.
                ...(codeSequence.manual ? { code } : {}),
                // A stale form must never re-opt-in a customer who replied STOP meanwhile, so consent
                // is sent only when the user changed it.
                ...(whatsapp_opt_in !== initialWhatsAppOptIn ? { whatsapp_opt_in } : {}),
                ...(acquirerChanged
                    ? { acquired_by_user_id: acquirer_id === '' ? null : acquirer_id }
                    : {}),
                ...(requireReason
                    ? { acquirer_correction_reason: acquirer_correction_reason.trim() }
                    : {}),
            }),
        );
        // Scalar fields only — addresses/contacts are managed independently.
        put(route('admin.customers.update', c.id), {
            preserveScroll: true,
            onError: (validationErrors) => {
                if (Object.keys(validationErrors).length > 0) {
                    setTab('customer');
                }
            },
            onSuccess: () => setData('acquirer_correction_reason', ''),
        });
    };

    const cancel = () => {
        // Discard the draft before leaving so stale values never resurface.
        reset();
        router.get(route('admin.customers.show', c.id));
    };

    return (
        <AdminLayout title="Ubah Pelanggan">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah Pelanggan"
                    subtitle={
                        readOnly
                            ? 'Tampilan baca saja. Anda tidak punya izin untuk mengubah pelanggan ini.'
                            : 'Ubah data pelanggan, alamat, dan kontaknya.'
                    }
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.customers.show', customer.data.id)}>
                                Kembali
                            </Link>
                        </Button>
                    }
                />
                <ConflictBanner />
                <Card>
                    <CardContent className="pt-6">
                        <Tabs value={tab} onValueChange={setTab}>
                            <TabList className="overflow-x-auto" aria-label="Bagian pelanggan">
                                <Tab value="customer">Data pelanggan</Tab>
                                <Tab value="addresses">Alamat ({addresses.length})</Tab>
                                <Tab value="contacts">Kontak ({contacts.length})</Tab>
                            </TabList>

                            <TabPanel value="customer">
                                {/* The only <form> on the page — nested editors render their own
                                    forms inside separate tab panels, so forms never nest. */}
                                <form
                                    onSubmit={submit}
                                    noValidate
                                    className="space-y-4"
                                    aria-busy={processing}
                                >
                                    <CustomerDataFields
                                        value={data}
                                        onChange={(key, value) => setData(key, value as never)}
                                        errors={formErrors}
                                        readOnly={readOnly}
                                        codeSequence={codeSequence}
                                        mode="edit"
                                    />
                                    <div className="space-y-2">
                                        <Checkbox
                                            label="Pelanggan setuju menerima notifikasi WhatsApp"
                                            description="Pesan yang dikirim: tagihan terbit, pengingat jatuh tempo, pembayaran diterima, status tiket, dan jadwal kunjungan teknisi. Pelanggan dapat berhenti kapan saja dengan membalas STOP."
                                            checked={data.whatsapp_opt_in}
                                            onCheckedChange={(checked) =>
                                                setData('whatsapp_opt_in', checked === true)
                                            }
                                            disabled={readOnly}
                                            error={errors.whatsapp_opt_in}
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            Tercatat: {consentStatus}
                                        </p>
                                        {!c.whatsapp.reachable && (
                                            <Alert variant="warning">
                                                <AlertDescription>
                                                    Nomor pelanggan ini bukan nomor seluler
                                                    Indonesia yang valid, sehingga pesan WhatsApp
                                                    tidak akan terkirim. Perbaiki nomor pada kolom
                                                    telepon.
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                    </div>
                                    {can.viewAcquirer ? (
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <CustomerAcquirerField
                                                options={acquirerOptions}
                                                value={data.acquirer_id}
                                                onChange={(value) => setData('acquirer_id', value)}
                                                error={acquirerErrors.acquired_by_user_id}
                                                disabled={processing}
                                                current={c.acquirer ?? null}
                                                readOnly={!acquirerEditable}
                                                requireReason={requireReason}
                                                reason={data.acquirer_correction_reason}
                                                onReasonChange={(value) =>
                                                    setData('acquirer_correction_reason', value)
                                                }
                                                reasonError={
                                                    acquirerErrors.acquirer_correction_reason
                                                }
                                            />
                                        </div>
                                    ) : null}
                                    <div className="flex justify-end gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={cancel}
                                            disabled={processing}
                                        >
                                            {readOnly ? 'Kembali' : 'Batal'}
                                        </Button>
                                        {!readOnly ? (
                                            <Button
                                                type="submit"
                                                loading={processing}
                                                disabled={processing}
                                            >
                                                Simpan pelanggan
                                            </Button>
                                        ) : null}
                                    </div>
                                </form>
                            </TabPanel>

                            <TabPanel value="addresses">
                                <CustomerAddressEditor
                                    customerId={c.id}
                                    addresses={addresses}
                                    regions={regions}
                                    geocodeResults={geocodeResults}
                                    geocodeIndex={geocodeIndex}
                                    canManage={can.address && !readOnly}
                                />
                            </TabPanel>

                            <TabPanel value="contacts">
                                <CustomerContactEditor
                                    customerId={c.id}
                                    contacts={contacts}
                                    canManage={can.contact && !readOnly}
                                />
                            </TabPanel>
                        </Tabs>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
