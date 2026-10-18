import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { usePermission } from '@/hooks/usePermission';
import { useCompany } from '@/hooks/useCompany';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EnumBadge,
    FileUpload,
    Input,
    Modal,
    Progress,
    SearchSelect,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
    useToast,
} from '@/Components/ui';
import {
    MediaPreview,
    PageHeader,
    RemoteSearchSelect,
    WorkOrderTimeline,
} from '@/Components/composite';
import type { RemoteOption, WorkOrderStep, WorkOrderStepKey } from '@/Components/composite';
import { SuggestionChip } from '@/Components/composite/SuggestionChip';
import { formatDate, formatDateTime } from '@/lib/format';
import { lowestLoad } from '@/lib/suggest';
import { PRIORITY, WORK_ORDER_STATUS, WORK_ORDER_TYPE } from '@/lib/statuses';
import type { DropPort } from '@/types/ipam-odn';
import { checkInText, type CheckInRecord } from '../Tickets/components/checkIn';

interface WoData {
    id: string;
    code: string;
    type: string;
    title: string;
    description?: string | null;
    status: string;
    priority: string;
    source: string;
    scheduled_date?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
    result?: string | null;
    rejection_reason?: string | null;
    customer?: { name: string; code: string } | null;
    subscription?: { code: string } | null;
    location?: { name: string; path?: string } | null;
    assigned_to?: string | null;
    updated_at?: string | null;
    assignee?: { name: string } | null;
    items?: {
        id: string;
        product_id: string;
        quantity_reserved: string;
        quantity_used: string;
        note?: string | null;
        product?: {
            sku: string;
            name: string;
            unit?: { name: string; symbol: string } | null;
        } | null;
        network_asset_id?: string | null;
        network_asset?: { code: string; name: string } | null;
    }[];
    assignments?: {
        id: string;
        assigned_at: string;
        unassigned_at?: string | null;
        technician?: { name: string } | null;
    }[];
    evidence?: {
        id: string;
        type: string;
        url: string;
        file_path: string;
        original_name?: string | null;
        mime_type?: string | null;
        caption?: string | null;
        uploaded_at: string;
    }[];
}

/** One person the assign dialog offers: `id` is the user the SPK is assigned to. */
interface TechRow {
    id: string;
    name: string;
    employee_number: string;
    organization?: string | null;
    phone?: string | null;
    /** Open SPK scheduled today (assigned or in progress). */
    open_today: number;
}

interface ShowProps extends Record<string, unknown> {
    workOrder: { data: WoData };
    /** Empty unless the viewer may assign this SPK. */
    technicians: TechRow[];
    /** Sent only after a search (`product_search`) and after a product is picked (`product_id`). */
    productOptions?: RemoteOption[];
    selectedProduct?: {
        id: string;
        type: string;
        unit?: { name: string; symbol: string } | null;
    } | null;
    networkAssetOptions?: RemoteOption[];
    /** The ODP port reserved for the customer (read-only); installation SPKs with a drop, odn.view only. */
    dropPort?: DropPort | null;
    can: { uploadEvidence: boolean };
    checkIn?: CheckInRecord | null;
    /** The four steps of the visit; `[]` for a cancelled SPK. */
    steps: WorkOrderStep[];
    currentStep: WorkOrderStepKey | null;
}

type ActionModal =
    'generate' | 'assign' | 'start' | 'submit' | 'approve' | 'reject' | 'cancel' | 'addItem';

const ACTION_TITLE: Record<ActionModal, (code: string) => string> = {
    generate: (code) => `Terbitkan SPK ${code}?`,
    assign: (code) => `Tugaskan SPK ${code}`,
    start: (code) => `Mulai pengerjaan SPK ${code}?`,
    submit: (code) => `Ajukan SPK ${code} untuk review?`,
    approve: (code) => `Setujui SPK ${code}?`,
    reject: (code) => `Tolak SPK ${code}?`,
    cancel: (code) => `Batalkan SPK ${code}?`,
    addItem: () => 'Tambah item',
};

const ACTION_DESCRIPTION: Partial<Record<ActionModal, string>> = {
    generate: 'SPK akan diterbitkan dan siap ditugaskan ke teknisi.',
    start: 'Waktu pengerjaan akan dicatat di audit log.',
    submit: 'Status berubah menjadi menunggu review.',
    approve: 'Pekerjaan dan penggunaan material disahkan permanen.',
    reject: 'SPK ditolak dan alasan Anda dicatat di riwayat.',
    cancel: 'SPK dibatalkan dan tidak bisa dilanjutkan lagi.',
};

const ACTION_CONFIRM: Record<ActionModal, string> = {
    generate: 'Terbitkan',
    assign: 'Tugaskan',
    start: 'Mulai',
    submit: 'Ajukan',
    approve: 'Setujui',
    reject: 'Tolak',
    cancel: 'Batalkan',
    addItem: 'Tambah',
};

const ADD_ITEM_KEYS = [
    'product_id',
    'network_asset_id',
    'quantity_reserved',
    'quantity_used',
    'note',
];

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium text-foreground">{children}</dd>
        </div>
    );
}

export default function Show({
    workOrder,
    technicians,
    productOptions,
    selectedProduct,
    networkAssetOptions,
    dropPort,
    can: permissions,
    checkIn,
    steps,
    currentStep,
}: ShowProps) {
    const w = workOrder.data;
    const company = useCompany();
    const { can } = usePermission();
    const { toast } = useToast();
    const [actionModal, setActionModal] = useState<ActionModal | null>(null);
    const [evidenceFile, setEvidenceFile] = useState<File | null>(null);
    const [evidenceCaption, setEvidenceCaption] = useState('');
    const [uploading, setUploading] = useState(false);
    const [uploadPercentage, setUploadPercentage] = useState(0);
    const { data, setData, post, processing, errors, reset, clearErrors, transform } = useForm({
        technician_id: '',
        reason: '',
        product_id: '',
        network_asset_id: '',
        quantity_reserved: '',
        quantity_used: '',
        note: '',
    });
    // Offered as a hint only: the person assigning decides.
    const lightest = lowestLoad(technicians);
    const technicianOptions = technicians.map((technician) => ({
        value: String(technician.id),
        label: technician.name,
        description: [technician.employee_number, technician.organization, technician.phone]
            .filter(Boolean)
            .join(' - '),
    }));
    const isAssetProduct = selectedProduct?.type === 'asset';
    const selectedUnit = selectedProduct?.unit
        ? `${selectedProduct.unit.name} (${selectedProduct.unit.symbol})`
        : null;
    const canChange = !['completed', 'cancelled'].includes(w.status);

    // The server answers with the picked product's kind and unit, and the free assets of that product.
    const pickProduct = (value: string) => {
        setData((previous) => ({ ...previous, product_id: value, network_asset_id: '' }));
        router.get(
            route('admin.spk.show', w.id),
            { product_id: value },
            {
                only: ['selectedProduct', 'networkAssetOptions'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const doAction = (e: FormEvent) => {
        e.preventDefault();
        if (!actionModal) return;

        const actionUrl =
            actionModal === 'addItem'
                ? route('admin.spk.items.store', w.id)
                : route(`admin.spk.${actionModal}`, w.id);

        // Optimistic-concurrency guard required by AssignWorkOrderRequest.
        transform((payload) =>
            actionModal === 'assign'
                ? {
                      ...payload,
                      expected_assigned_to: w.assigned_to ?? null,
                      expected_updated_at: w.updated_at ?? null,
                  }
                : payload,
        );
        post(actionUrl, {
            onSuccess: () => {
                if (actionModal === 'addItem') {
                    reset(
                        'product_id',
                        'network_asset_id',
                        'quantity_reserved',
                        'quantity_used',
                        'note',
                    );
                }
                setActionModal(null);
            },
            onError: (formErrors) => {
                // Keep the draft in the add-item dialog while the server (422) rejects a field of it;
                // close only when the error is about something else.
                if (actionModal === 'addItem' && !ADD_ITEM_KEYS.some((key) => key in formErrors)) {
                    setActionModal(null);
                }
            },
        });
    };

    const closeModal = () => {
        setActionModal(null);
        clearErrors();
    };

    const reloadWorkOrder = () =>
        router.reload({ only: ['workOrder'], onSuccess: () => clearErrors() });

    // Server-side keys (conflict, expected_*) are not part of the form's data shape.
    const serverErrors: Record<string, string | undefined> = errors;
    const staleError =
        serverErrors.conflict ??
        serverErrors.expected_assigned_to ??
        serverErrors.expected_updated_at;

    const uploadEvidence = (e: FormEvent) => {
        e.preventDefault();
        if (!evidenceFile || uploading) return;

        const formData = new FormData();
        formData.append('file', evidenceFile);
        formData.append('caption', evidenceCaption);

        setUploading(true);
        setUploadPercentage(0);
        router.post(route('admin.spk.evidence.store', w.id), formData, {
            forceFormData: true,
            onProgress: (event) => setUploadPercentage(event?.percentage ?? 0),
            onSuccess: () => {
                setEvidenceFile(null);
                setEvidenceCaption('');
                toast({ title: 'Bukti berhasil diunggah.', variant: 'success' });
            },
            onError: () =>
                toast({
                    title: 'Bukti gagal diunggah.',
                    description: 'Periksa jenis berkas yang diizinkan dan ukuran maksimal 10 MB.',
                    variant: 'danger',
                }),
            onFinish: () => setUploading(false),
        });
    };

    return (
        <AdminLayout title={w.title}>
            <div className="space-y-6">
                <PageHeader
                    title={w.title}
                    subtitle={w.code}
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.spk.index')}>Kembali</Link>
                        </Button>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Rincian</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-x-6 gap-y-4 text-sm md:grid-cols-2">
                                <Detail label="Status">
                                    <EnumBadge map={WORK_ORDER_STATUS} value={w.status} />
                                </Detail>
                                <Detail label="Jenis">
                                    <EnumBadge map={WORK_ORDER_TYPE} value={w.type} />
                                </Detail>
                                <Detail label="Prioritas">
                                    <EnumBadge map={PRIORITY} value={w.priority} />
                                </Detail>
                                <Detail label="Pelanggan">{w.customer?.name ?? '-'}</Detail>
                                <Detail label="Langganan">{w.subscription?.code ?? '-'}</Detail>
                                <Detail label="Lokasi">{w.location?.name ?? '-'}</Detail>
                                {dropPort && (
                                    <Detail label="Port drop">
                                        <Link
                                            href={route(
                                                'admin.odn.assets.show',
                                                dropPort.odp_asset_id,
                                            )}
                                            className="text-primary-text hover:underline"
                                        >
                                            {dropPort.odp_code}
                                        </Link>{' '}
                                        ({dropPort.odp_name}), port {dropPort.number}
                                        {dropPort.location_path && (
                                            <span className="block text-xs font-normal text-muted-foreground">
                                                {dropPort.location_path}
                                            </span>
                                        )}
                                    </Detail>
                                )}
                                <Detail label="Teknisi">{w.assignee?.name ?? '-'}</Detail>
                                <Detail label="Jadwal">
                                    {formatDate(w.scheduled_date, company)}
                                </Detail>
                                <Detail label="Dimulai">
                                    {formatDateTime(w.started_at, company)}
                                </Detail>
                                <Detail label="Check-in">{checkInText(checkIn)}</Detail>
                                <Detail label="Selesai">
                                    {formatDateTime(w.completed_at, company)}
                                </Detail>
                                {w.description && (
                                    <div className="md:col-span-2">
                                        <Detail label="Deskripsi">{w.description}</Detail>
                                    </div>
                                )}
                                {w.result && (
                                    <div className="md:col-span-2">
                                        <Detail label="Hasil">{w.result}</Detail>
                                    </div>
                                )}
                                {w.rejection_reason && (
                                    <div className="md:col-span-2">
                                        <Detail label="Alasan penolakan">
                                            {w.rejection_reason}
                                        </Detail>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Tindakan</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-wrap gap-2">
                                    {w.status === 'draft' && (
                                        <Button
                                            type="button"
                                            onClick={() => setActionModal('generate')}
                                        >
                                            Terbitkan
                                        </Button>
                                    )}
                                    {(w.status === 'draft' ||
                                        w.status === 'generated' ||
                                        w.status === 'rejected') && (
                                        <Button
                                            type="button"
                                            onClick={() => setActionModal('assign')}
                                        >
                                            Tugaskan
                                        </Button>
                                    )}
                                    {w.status === 'assigned' && (
                                        <Button
                                            type="button"
                                            onClick={() => setActionModal('start')}
                                        >
                                            Mulai
                                        </Button>
                                    )}
                                    {w.status === 'in_progress' && (
                                        <Button
                                            type="button"
                                            onClick={() => setActionModal('submit')}
                                        >
                                            Ajukan review
                                        </Button>
                                    )}
                                    {w.status === 'waiting_review' && can('spk.approve') && (
                                        <Button
                                            type="button"
                                            onClick={() => setActionModal('approve')}
                                        >
                                            Setujui
                                        </Button>
                                    )}
                                    {w.status === 'waiting_review' && can('spk.reject') && (
                                        <Button
                                            type="button"
                                            variant="danger"
                                            onClick={() => setActionModal('reject')}
                                        >
                                            Tolak
                                        </Button>
                                    )}
                                    {canChange && (
                                        <Button
                                            type="button"
                                            variant="destructive-outline"
                                            onClick={() => setActionModal('cancel')}
                                        >
                                            Batalkan
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Tahap kunjungan</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <WorkOrderTimeline
                                    steps={steps}
                                    currentStep={currentStep}
                                    status={w.status}
                                />
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <Card>
                    <CardHeader className="space-y-0 flex-row items-center justify-between">
                        <CardTitle>Item</CardTitle>
                        {canChange && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setActionModal('addItem')}
                            >
                                Tambah item
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Barang</TH>
                                    <TH>Aset jaringan</TH>
                                    <TH>Satuan</TH>
                                    <TH>Dicadangkan</TH>
                                    <TH>Terpakai</TH>
                                    <TH>Catatan</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {(w.items ?? []).length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={6}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            Belum ada barang di SPK ini.
                                            {canChange && ' Pakai "Tambah item" untuk mencatatnya.'}
                                        </TD>
                                    </TR>
                                ) : (
                                    (w.items ?? []).map((i) => (
                                        <TR key={i.id}>
                                            <TD>
                                                {i.product
                                                    ? `${i.product.sku} - ${i.product.name}`
                                                    : `#${i.product_id}`}
                                            </TD>
                                            <TD>
                                                {i.network_asset
                                                    ? `${i.network_asset.code} - ${i.network_asset.name}`
                                                    : i.network_asset_id
                                                      ? `#${i.network_asset_id}`
                                                      : '-'}
                                            </TD>
                                            <TD>
                                                {i.product?.unit?.symbol ??
                                                    i.product?.unit?.name ??
                                                    '-'}
                                            </TD>
                                            <TD>{i.quantity_reserved}</TD>
                                            <TD>{i.quantity_used}</TD>
                                            <TD>{i.note ?? '-'}</TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Bukti</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {permissions.uploadEvidence && (
                            <form
                                onSubmit={uploadEvidence}
                                className="grid gap-3 lg:grid-cols-[1fr_1fr_auto] lg:items-start"
                            >
                                <FileUpload
                                    label="Berkas bukti"
                                    value={evidenceFile}
                                    onChange={setEvidenceFile}
                                    acceptedFileTypes={[
                                        'image/jpeg',
                                        'image/png',
                                        'application/pdf',
                                        'application/msword',
                                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                    ]}
                                    required
                                />
                                <Input
                                    label="Keterangan"
                                    value={evidenceCaption}
                                    onChange={(event) => setEvidenceCaption(event.target.value)}
                                    placeholder="Keterangan (opsional)"
                                />
                                <div className="self-end">
                                    <Button
                                        type="submit"
                                        disabled={!evidenceFile || uploading}
                                        loading={uploading}
                                    >
                                        Unggah
                                    </Button>
                                </div>
                                {uploading && (
                                    <Progress
                                        className="lg:col-span-3"
                                        label="Mengunggah bukti"
                                        value={uploadPercentage}
                                    />
                                )}
                            </form>
                        )}
                        {(w.evidence ?? []).length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum ada bukti. Teknisi mengunggah foto atau dokumen pekerjaan di
                                sini.
                            </p>
                        ) : (
                            <div className="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
                                {(w.evidence ?? []).map((ev) => (
                                    <div
                                        key={ev.id}
                                        className="rounded-lg border border-border p-3"
                                    >
                                        <p className="text-sm font-medium">
                                            {ev.type === 'photo' ? 'Foto' : 'Dokumen'}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {ev.caption ?? ev.original_name ?? ev.file_path}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {formatDateTime(ev.uploaded_at, company)}
                                        </p>
                                        <MediaPreview media={ev} />
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Riwayat penugasan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Teknisi</TH>
                                    <TH>Ditugaskan</TH>
                                    <TH>Dicabut</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {(w.assignments ?? []).length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={3}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            SPK ini belum pernah ditugaskan.
                                        </TD>
                                    </TR>
                                ) : (
                                    (w.assignments ?? []).map((a) => (
                                        <TR key={a.id}>
                                            <TD>{a.technician?.name ?? '-'}</TD>
                                            <TD className="text-sm">
                                                {formatDateTime(a.assigned_at, company)}
                                            </TD>
                                            <TD className="text-sm">
                                                {formatDateTime(a.unassigned_at, company)}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Modal
                    open={actionModal !== null}
                    onClose={closeModal}
                    title={actionModal ? ACTION_TITLE[actionModal](w.code) : ''}
                >
                    <form onSubmit={doAction} className="space-y-4">
                        {actionModal && ACTION_DESCRIPTION[actionModal] ? (
                            <p className="text-sm text-muted-foreground">
                                {ACTION_DESCRIPTION[actionModal]}
                            </p>
                        ) : null}
                        {staleError && (
                            <Alert variant="destructive">
                                <AlertTitle>SPK sudah berubah</AlertTitle>
                                <AlertDescription className="space-y-2">
                                    <p>{staleError}</p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={reloadWorkOrder}
                                    >
                                        Muat ulang SPK
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}
                        {actionModal === 'assign' && (
                            <div className="space-y-2">
                                <SearchSelect
                                    label="Teknisi"
                                    value={data.technician_id}
                                    onChange={(value) => setData('technician_id', value)}
                                    options={technicianOptions}
                                    placeholder="Cari karyawan teknisi"
                                    emptyText="Tidak ada karyawan teknisi yang cocok."
                                    error={errors.technician_id}
                                    required
                                />
                                {lightest && data.technician_id === '' && (
                                    <SuggestionChip
                                        value={`${lightest.name} (beban terendah: ${lightest.open_today} SPK hari ini)`}
                                        onUse={() => setData('technician_id', String(lightest.id))}
                                    />
                                )}
                            </div>
                        )}
                        {(actionModal === 'reject' || actionModal === 'cancel') && (
                            <Input
                                label="Alasan"
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                                error={errors.reason}
                                required
                            />
                        )}
                        {actionModal === 'addItem' && (
                            <>
                                <RemoteSearchSelect
                                    field="product"
                                    label="Barang"
                                    options={productOptions}
                                    value={data.product_id}
                                    onChange={pickProduct}
                                    placeholder="Cari nama atau SKU barang"
                                    error={errors.product_id}
                                    required
                                />
                                {isAssetProduct && (
                                    <RemoteSearchSelect
                                        field="networkAsset"
                                        label="Aset jaringan"
                                        options={networkAssetOptions}
                                        value={data.network_asset_id}
                                        onChange={(value) => setData('network_asset_id', value)}
                                        placeholder="Cari aset yang masih tersedia"
                                        error={errors.network_asset_id}
                                        required
                                    />
                                )}
                                <Input
                                    label={
                                        selectedUnit
                                            ? `Jumlah dicadangkan - ${selectedUnit}`
                                            : 'Jumlah dicadangkan'
                                    }
                                    type="number"
                                    step="0.01"
                                    value={data.quantity_reserved}
                                    onChange={(e) => setData('quantity_reserved', e.target.value)}
                                    error={errors.quantity_reserved}
                                />
                                <Input
                                    label={
                                        selectedUnit
                                            ? `Jumlah terpakai - ${selectedUnit}`
                                            : 'Jumlah terpakai'
                                    }
                                    type="number"
                                    step="0.01"
                                    value={data.quantity_used}
                                    onChange={(e) => setData('quantity_used', e.target.value)}
                                    error={errors.quantity_used}
                                />
                                <Input
                                    label="Catatan"
                                    value={data.note}
                                    onChange={(e) => setData('note', e.target.value)}
                                    error={errors.note}
                                />
                            </>
                        )}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={closeModal}>
                                Batal
                            </Button>
                            <Button
                                type="submit"
                                variant={
                                    actionModal === 'reject' || actionModal === 'cancel'
                                        ? 'danger'
                                        : 'default'
                                }
                                loading={processing}
                            >
                                {actionModal ? ACTION_CONFIRM[actionModal] : 'Simpan'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
