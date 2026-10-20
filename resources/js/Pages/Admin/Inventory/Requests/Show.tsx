import { useState, useRef, useEffect, useCallback } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import {
    ArrowLeft,
    CheckCircle2,
    Clock,
    Package,
    Truck,
    XCircle,
    RotateCcw,
    Send,
    AlertCircle,
    FileText,
    ShieldAlert,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { EmptyCell } from '@/Components/ui/EmptyCell';
import { EnumBadge } from '@/Components/ui/EnumBadge';
import { Button, buttonVariants } from '@/Components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/Card';
import { Table, TBody, TD, TH, THead, TR } from '@/Components/ui/Table';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Textarea } from '@/Components/ui/Textarea';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import { formatDate, formatNumber } from '@/lib/format';
import { useCompany } from '@/hooks/useCompany';
import { usePermission } from '@/hooks/usePermission';
import type { PageProps } from '@/types';
import type { InventoryRequestRow, MaterialReturnCaseRow } from '@/types/inventory';
import {
    INVENTORY_REQUEST_SOURCE,
    INVENTORY_REQUEST_STATUS,
    MATERIAL_RETURN_STATUS,
    labelOf,
} from '@/lib/statuses';

interface Props {
    request: {
        data: InventoryRequestRow;
    };
    /** What each line can still reserve (item id to quantity minus reserved); null unless the viewer may approve the waiting request. */
    stockAvailable?: Record<string, string> | null;
}

/** One line of the warehouse's physical acceptance: what came back, in the four ways a returned quantity can be booked. */
interface AcceptLine {
    id: string;
    product_name?: string;
    issued_qty: number;
    actual_used_qty: number;
    declared_good_return_qty: number;
    accepted_good_return_qty: number;
    damaged_return_qty: number;
    lost_qty: number;
    other_discrepancy_qty: number;
}

const ACCEPT_FIELDS = [
    { key: 'accepted_good_return_qty', label: 'Baik' },
    { key: 'damaged_return_qty', label: 'Rusak' },
    { key: 'lost_qty', label: 'Hilang' },
    { key: 'other_discrepancy_qty', label: 'Lainnya' },
] as const;

type AcceptField = (typeof ACCEPT_FIELDS)[number]['key'];

/**
 * What is missing (positive) or too much (negative) when the technician's use and the four columns are set against what
 * the warehouse handed over. The server refuses a line that does not add up, and rounds to cents the same way.
 */
function reconcileGap(line: AcceptLine): number {
    const booked =
        line.actual_used_qty +
        line.accepted_good_return_qty +
        line.damaged_return_qty +
        line.lost_qty +
        line.other_discrepancy_qty;

    return Math.round((line.issued_qty - booked) * 100) / 100;
}

export default function InventoryRequestsShow({ request, stockAvailable }: Props) {
    const r = request.data;
    const items = r.items || [];
    const company = useCompany();

    const { can } = usePermission();
    const { auth } = usePage<PageProps>().props;
    const currentUserId = auth.user?.id;

    // State for material return custody case
    const [returnCase, setReturnCase] = useState<MaterialReturnCaseRow | null>(null);
    const [returnLoading, setReturnLoading] = useState(false);
    const [returnError, setReturnError] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);

    // Form for Return Declaration (Technician)
    const [declareLines, setDeclareLines] = useState<
        Array<{
            id: string;
            product_name?: string;
            issued_qty: number;
            actual_used_qty: number;
            declared_good_return_qty: number;
            declared_discrepancy_qty: number;
            discrepancy_code: string;
            discrepancy_reason: string;
        }>
    >([]);

    // Form for Warehouse Physical Acceptance
    const [acceptLines, setAcceptLines] = useState<AcceptLine[]>([]);

    const fetchReturnCase = useCallback(() => {
        if (r.status === 'issued') {
            setReturnLoading(true);
            setReturnError(null);
            const targetUrl = (
                window as unknown as {
                    route?: (name: string, params?: Record<string, unknown>) => string;
                }
            ).route
                ? route('admin.inventory.returns.index', { inventory_request_id: r.id })
                : `/admin/inventory/returns?inventory_request_id=${r.id}`;

            fetch(targetUrl, {
                headers: { Accept: 'application/json' },
            })
                .then(async (res) => {
                    if (!res.ok) {
                        const errData = (await res.json().catch(() => ({}))) as {
                            message?: string;
                        };
                        throw new Error(
                            errData.message ||
                                `Gagal memuat berkas pengembalian material (${res.status}).`,
                        );
                    }
                    return res.json() as Promise<{ data?: MaterialReturnCaseRow[] }>;
                })
                .then((json) => {
                    if (json && Array.isArray(json.data) && json.data.length > 0) {
                        const first = json.data[0];
                        setReturnCase(first);
                        if (first?.lines) {
                            setDeclareLines(
                                first.lines.map((line) => {
                                    const issued = line.issued_qty || 0;
                                    return {
                                        id: line.id,
                                        product_name: line.product?.name,
                                        issued_qty: issued,
                                        actual_used_qty:
                                            line.actual_used_qty > 0
                                                ? line.actual_used_qty
                                                : issued,
                                        declared_good_return_qty: line.declared_good_return_qty,
                                        declared_discrepancy_qty: line.declared_discrepancy_qty,
                                        discrepancy_code: 'damaged',
                                        discrepancy_reason: '',
                                    };
                                }),
                            );
                            setAcceptLines(
                                first.lines.map((line) => ({
                                    id: line.id,
                                    product_name: line.product?.name,
                                    issued_qty: line.issued_qty,
                                    actual_used_qty: line.actual_used_qty,
                                    declared_good_return_qty: line.declared_good_return_qty,
                                    // The technician's declaration is the starting point: the good quantity as declared, the
                                    // difference in the column of its kind (a difference without a kind is "Lainnya").
                                    accepted_good_return_qty: line.declared_good_return_qty,
                                    damaged_return_qty:
                                        line.discrepancy_code === 'damaged'
                                            ? line.declared_discrepancy_qty
                                            : 0,
                                    lost_qty:
                                        line.discrepancy_code === 'lost'
                                            ? line.declared_discrepancy_qty
                                            : 0,
                                    other_discrepancy_qty:
                                        line.discrepancy_code === 'damaged' ||
                                        line.discrepancy_code === 'lost'
                                            ? 0
                                            : line.declared_discrepancy_qty,
                                })),
                            );
                        }
                    } else {
                        setReturnCase(null);
                    }
                })
                .catch((err: unknown) => {
                    const message =
                        err instanceof Error ? err.message : 'Gagal memuat status pengembalian.';
                    setReturnError(message);
                })
                .finally(() => setReturnLoading(false));
        }
    }, [r.status, r.id]);

    useEffect(() => {
        if (r.status === 'issued') {
            void Promise.resolve().then(() => {
                fetchReturnCase();
            });
        }
    }, [r.status, fetchReturnCase]);

    // Authoritative capabilities for return custody
    const canDeclareReturn = returnCase
        ? returnCase.can !== undefined
            ? Boolean(returnCase.can.declare)
            : Boolean(
                  can('inventory.return.declare') &&
                  ['awaiting_declaration', 'correction_required'].includes(returnCase.status) &&
                  (currentUserId ? returnCase.technician_id === currentUserId : true),
              )
        : false;

    const canReceiveReturn = returnCase
        ? returnCase.can !== undefined
            ? Boolean(returnCase.can.receive)
            : Boolean(
                  can('inventory.return.receive') &&
                  returnCase.status === 'awaiting_receipt' &&
                  (currentUserId ? returnCase.technician_id !== currentUserId : true),
              )
        : false;

    const canResolveReturn = returnCase
        ? returnCase.can !== undefined
            ? Boolean(returnCase.can.resolve)
            : Boolean(
                  can('inventory.return.resolve') &&
                  returnCase.status === 'exception_open' &&
                  (currentUserId
                      ? returnCase.technician_id !== currentUserId &&
                        returnCase.received_by !== currentUserId
                      : true),
              )
        : false;

    // Modal dialogs state
    const [dialogOpen, setDialogOpen] = useState<
        | 'submit'
        | 'approve'
        | 'prepare'
        | 'ready'
        | 'issue'
        | 'reject'
        | 'cancel'
        | 'declare'
        | 'receiveAccept'
        | 'receiveReject'
        | 'resolve'
        | null
    >(null);

    // Double-submit guard
    const inFlight = useRef(false);
    const [actionLoading, setActionLoading] = useState(false);

    // Form for Approve
    const [approveNote, setApproveNote] = useState('');
    const [approvedQtys, setApprovedQtys] = useState<Record<string, number>>(() => {
        const init: Record<string, number> = {};
        items.forEach((item) => {
            init[item.id] = item.quantity_requested;
        });
        return init;
    });

    // A line may not take more than the stock that is free where the approval looks for it (the server checks it again).
    const overStock = (itemId: string, quantity: number): boolean => {
        const available = stockAvailable?.[itemId];

        return (
            available !== undefined &&
            Math.round(quantity * 100) > Math.round(Number(available) * 100)
        );
    };
    const approveBlocked = items.some((item) =>
        overStock(item.id, approvedQtys[item.id] ?? item.quantity_requested),
    );
    const acceptBlocked = acceptLines.some((line) => reconcileGap(line) !== 0);

    const updateAcceptLine = (index: number, field: AcceptField, value: string) => {
        setAcceptLines((lines) =>
            lines.map((line, position) =>
                position === index ? { ...line, [field]: parseFloat(value) || 0 } : line,
            ),
        );
        if (actionError) setActionError(null);
    };

    // Form for Reject
    const [rejectReason, setRejectReason] = useState('');

    // Form for Cancel
    const [cancelReason, setCancelReason] = useState('');

    // Form for Warehouse Physical Rejection
    const [returnRejectReason, setReturnRejectReason] = useState('');

    // Form for Resolver Resolve Exception
    const [resolutionNote, setResolutionNote] = useState('');

    // Actions for standard requests
    const handleAction = (endpoint: string, data?: Record<string, unknown>) => {
        if (inFlight.current) return;
        inFlight.current = true;
        setActionLoading(true);
        setActionError(null);

        router.post(endpoint, data as unknown as Record<string, string>, {
            preserveScroll: true,
            onSuccess: () => {
                setDialogOpen(null);
            },
            onError: (errs) => {
                const firstErr = Object.values(errs)[0];
                if (firstErr) {
                    setActionError(String(firstErr));
                }
            },
            onFinish: () => {
                inFlight.current = false;
                setActionLoading(false);
            },
        });
    };

    // Actions for return custody endpoints
    const handleReturnApiAction = (url: string, payload: Record<string, unknown>) => {
        if (inFlight.current) return;
        inFlight.current = true;
        setActionLoading(true);
        setActionError(null);

        // axios sends the XSRF-TOKEN cookie as X-XSRF-TOKEN; the layout renders no csrf-token meta tag to read.
        axios
            .post(url, payload, { headers: { Accept: 'application/json' } })
            .then(() => {
                setDialogOpen(null);
                fetchReturnCase();
                router.reload({ only: ['request'] });
            })
            .catch((err: unknown) => {
                const message = isAxiosError<{ message?: string }>(err)
                    ? (err.response?.data?.message ?? 'Gagal memproses pengembalian material.')
                    : 'Terjadi kesalahan sistem.';
                setActionError(message);
            })
            .finally(() => {
                inFlight.current = false;
                setActionLoading(false);
            });
    };

    const timelineSteps = [
        {
            key: 'draft',
            title: 'Draf',
            actor: r.requester?.name,
            timestamp: r.created_at,
            done: true,
        },
        {
            key: 'submitted',
            title: 'Diajukan',
            actor: r.requester?.name,
            timestamp: r.submitted_at ?? null,
            done: r.status !== 'draft',
        },
        {
            key: 'approved_reserved',
            title: 'Disetujui & Direservasi',
            actor: r.approver?.name,
            timestamp: r.approved_at ?? null,
            done: ['approved_reserved', 'preparing', 'ready_for_pickup', 'issued'].includes(
                r.status,
            ),
        },
        {
            key: 'preparing',
            title: 'Sedang Disiapkan Gudang',
            actor: r.preparer?.name,
            timestamp: r.prepared_at ?? null,
            done: ['preparing', 'ready_for_pickup', 'issued'].includes(r.status),
        },
        {
            key: 'ready_for_pickup',
            title: 'Siap Diambil',
            actor: r.ready_user?.name,
            timestamp: r.ready_at ?? null,
            done: ['ready_for_pickup', 'issued'].includes(r.status),
        },
        {
            key: 'issued',
            title: 'Selesai Diserahkan',
            actor: r.issuer?.name,
            timestamp: r.issued_at ?? null,
            done: r.status === 'issued',
        },
    ];

    return (
        <AdminLayout title={`Permintaan Barang ${r.code}`}>
            <div className="mx-auto space-y-6 max-w-5xl">
                <PageHeader
                    title={`Permintaan Barang ${r.code}`}
                    description={`Diajukan oleh ${r.requester?.name ?? 'Pemohon'} pada ${formatDate(r.created_at, company)}`}
                    actions={
                        <div className="flex items-center gap-2">
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.inventory.requests.index')}>Kembali</Link>
                            </Button>

                            {/* Lifecycle Action Buttons */}
                            {r.can?.submit && (
                                <Button
                                    size="sm"
                                    variant="primary"
                                    onClick={() => {
                                        setActionError(null);
                                        setDialogOpen('submit');
                                    }}
                                    disabled={actionLoading}
                                    className="gap-1.5"
                                >
                                    <Send className="h-4 w-4" />
                                    Ajukan ke Gudang
                                </Button>
                            )}

                            {r.can?.approve && (
                                <Button
                                    size="sm"
                                    variant="primary"
                                    onClick={() => {
                                        setActionError(null);
                                        setDialogOpen('approve');
                                    }}
                                    disabled={actionLoading}
                                    className="gap-1.5"
                                >
                                    <CheckCircle2 className="h-4 w-4" />
                                    Setujui & Reservasi Stok
                                </Button>
                            )}

                            {r.can?.reject && (
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() => {
                                        setActionError(null);
                                        setDialogOpen('reject');
                                    }}
                                    disabled={actionLoading}
                                    className="gap-1.5"
                                >
                                    <XCircle className="h-4 w-4" />
                                    Tolak Permintaan
                                </Button>
                            )}

                            {r.can?.prepare && (
                                <Button
                                    size="sm"
                                    variant="primary"
                                    onClick={() => {
                                        setActionError(null);
                                        setDialogOpen('prepare');
                                    }}
                                    disabled={actionLoading}
                                    className="gap-1.5"
                                >
                                    <Package className="h-4 w-4" />
                                    Mulai Penyiapan Barang
                                </Button>
                            )}

                            {r.can?.ready && (
                                <Button
                                    size="sm"
                                    variant="primary"
                                    onClick={() => {
                                        setActionError(null);
                                        setDialogOpen('ready');
                                    }}
                                    disabled={actionLoading}
                                    className="gap-1.5"
                                >
                                    <Truck className="h-4 w-4" />
                                    Tandai Siap Diambil
                                </Button>
                            )}

                            {r.can?.issue && (
                                <Button
                                    size="sm"
                                    variant="primary"
                                    onClick={() => {
                                        setActionError(null);
                                        setDialogOpen('issue');
                                    }}
                                    disabled={actionLoading}
                                    className="gap-1.5"
                                >
                                    <CheckCircle2 className="h-4 w-4" />
                                    Serah Terima & Kurangi Stok
                                </Button>
                            )}

                            {r.can?.cancel && (
                                <Button
                                    size="sm"
                                    variant="destructive-outline"
                                    onClick={() => {
                                        setActionError(null);
                                        setDialogOpen('cancel');
                                    }}
                                    disabled={actionLoading}
                                    className="gap-1.5"
                                >
                                    <XCircle className="h-4 w-4" />
                                    Batalkan Permintaan
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* Status & Banner Alerts */}
                {r.status === 'rejected' && (
                    <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive-text">
                        <div className="mb-1 flex items-center gap-2 font-medium">
                            <XCircle className="h-4 w-4" />
                            <span>Permintaan Barang Ditolak</span>
                        </div>
                        <p className="text-xs">
                            Alasan Penolakan:{' '}
                            <strong>{r.rejection_reason || 'Tidak ada alasan rinci.'}</strong>
                        </p>
                    </div>
                )}

                {r.status === 'cancelled' && (
                    <div className="rounded-lg border border-muted bg-muted/30 p-4 text-sm text-muted-foreground">
                        <div className="mb-1 flex items-center gap-2 font-medium text-foreground">
                            <XCircle className="h-4 w-4" />
                            <span>Permintaan Dibatalkan</span>
                        </div>
                        <p className="text-xs">
                            Alasan Pembatalan: {r.cancellation_reason || 'Dibatalkan oleh pemohon.'}
                        </p>
                    </div>
                )}

                {/* Timeline Lifecycle */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Clock className="h-4 w-4 text-primary-text" />
                            Alur & Jejak Status Permintaan
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                            {timelineSteps.map((step) => (
                                <div
                                    key={step.key}
                                    className={`flex flex-col rounded-lg border p-3 text-xs ${
                                        step.done
                                            ? 'border-success/50 bg-success/5 font-medium text-foreground'
                                            : 'border-border bg-muted/20 text-muted-foreground'
                                    }`}
                                >
                                    <div className="mb-1 flex items-center gap-1.5">
                                        <div
                                            className={`size-2 rounded-full ${
                                                step.done ? 'bg-success' : 'bg-muted-foreground/40'
                                            }`}
                                        />
                                        <span className="font-semibold">{step.title}</span>
                                    </div>
                                    {step.actor && (
                                        <span className="truncate text-xs text-muted-foreground">
                                            {step.actor}
                                        </span>
                                    )}
                                    {step.timestamp && (
                                        <span className="mt-auto text-xs text-muted-foreground">
                                            {formatDate(step.timestamp, company)}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Main Information */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <div className="space-y-6 md:col-span-2">
                        {/* Table of Items */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Package className="h-4 w-4 text-primary-text" />
                                    Daftar Barang yang Diminta
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <THead>
                                        <TR>
                                            <TH>Barang</TH>
                                            <TH className="text-right">Jumlah Diminta</TH>
                                            <TH className="text-right">Jumlah Disetujui</TH>
                                            <TH className="text-right">Jumlah Diserahkan</TH>
                                            <TH>Catatan</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {items.map((item) => {
                                            const unit = item.product?.unit ?? '';
                                            return (
                                                <TR key={item.id}>
                                                    <TD className="font-medium">
                                                        <div>{item.product?.name ?? '-'}</div>
                                                        {item.product?.code && (
                                                            <div className="font-mono text-xs text-muted-foreground">
                                                                {item.product.code}
                                                            </div>
                                                        )}
                                                    </TD>
                                                    <TD className="text-right font-medium">
                                                        {formatNumber(item.quantity_requested)}{' '}
                                                        {unit}
                                                    </TD>
                                                    <TD className="text-right">
                                                        {item.quantity_approved !== null &&
                                                        item.quantity_approved !== undefined ? (
                                                            <span className="font-semibold text-brand-600 dark:text-brand-400">
                                                                {formatNumber(
                                                                    item.quantity_approved,
                                                                )}{' '}
                                                                {unit}
                                                            </span>
                                                        ) : (
                                                            <EmptyCell />
                                                        )}
                                                    </TD>
                                                    <TD className="text-right">
                                                        {item.quantity_issued > 0 ? (
                                                            <span className="font-semibold text-emerald-700 dark:text-emerald-400">
                                                                {formatNumber(item.quantity_issued)}{' '}
                                                                {unit}
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">
                                                                0 {unit}
                                                            </span>
                                                        )}
                                                    </TD>
                                                    <TD className="text-xs text-muted-foreground">
                                                        {item.note || <EmptyCell />}
                                                    </TD>
                                                </TR>
                                            );
                                        })}
                                    </TBody>
                                </Table>
                            </CardContent>
                        </Card>

                        {/* Material Return & Custody Tracking Section */}
                        {r.status === 'issued' && (
                            <Card className="border-brand-500/30">
                                <CardHeader className="flex flex-row items-center justify-between pb-3">
                                    <div>
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <RotateCcw className="h-4 w-4 text-brand-600" />
                                            Pelaporan Pemakaian Aktual & Pengembalian Material
                                        </CardTitle>
                                        <CardDescription>
                                            Teknisi melaporkan pemakaian riil & sisa barang. Petugas
                                            gudang melakukan verifikasi fisik sebelum stok
                                            disesuaikan.
                                        </CardDescription>
                                    </div>
                                    {returnCase && (
                                        <EnumBadge
                                            map={MATERIAL_RETURN_STATUS}
                                            value={returnCase.status}
                                        />
                                    )}
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-1 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground">
                                        <div className="flex items-center gap-1.5 font-medium text-foreground">
                                            <AlertCircle className="size-3.5 text-brand-600" />
                                            Aturan Integritas Stok
                                        </div>
                                        <p>
                                            Deklarasi teknisi{' '}
                                            <strong>tidak langsung mengubah stok gudang</strong>.
                                            Stok barang baik hanya bertambah setelah petugas gudang
                                            menyetujui penerimaan fisik pada status{' '}
                                            <em>
                                                {labelOf(
                                                    MATERIAL_RETURN_STATUS,
                                                    'awaiting_receipt',
                                                )}
                                            </em>
                                            . Kasus berstatus{' '}
                                            <em>
                                                {labelOf(MATERIAL_RETURN_STATUS, 'exception_open')}
                                            </em>{' '}
                                            perlu diselesaikan atasan yang berwenang sebelum
                                            ditutup.
                                        </p>
                                    </div>

                                    {/* Accessible Error State for Return Fetching */}
                                    {returnError && (
                                        <div
                                            role="alert"
                                            aria-live="assertive"
                                            className="flex items-center justify-between rounded-lg border border-destructive/50 bg-destructive/10 p-3 text-xs text-destructive-text"
                                        >
                                            <div className="flex items-center gap-2">
                                                <AlertCircle className="h-4 w-4 shrink-0" />
                                                <span>{returnError}</span>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="destructive-outline"
                                                size="sm"
                                                onClick={fetchReturnCase}
                                                className="h-7 text-xs"
                                            >
                                                <RotateCcw className="mr-1 h-3 w-3" />
                                                Coba lagi
                                            </Button>
                                        </div>
                                    )}

                                    {/* Alert / Reason Notes */}
                                    {returnCase?.status === 'correction_required' &&
                                        returnCase.rejection_reason && (
                                            <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-3 text-xs text-destructive-text">
                                                <div className="mb-1 flex items-center gap-1.5 font-medium">
                                                    <XCircle className="size-3.5 shrink-0" />
                                                    <span>Penyerahan Fisik Ditolak Gudang</span>
                                                </div>
                                                <p>
                                                    Alasan Penolakan:{' '}
                                                    <strong>{returnCase.rejection_reason}</strong>
                                                </p>
                                            </div>
                                        )}

                                    {returnCase?.status === 'exception_open' && (
                                        <div className="rounded-lg border border-warning/50 bg-warning/10 p-3 text-xs text-amber-800 dark:text-amber-300">
                                            <div className="mb-1 flex items-center gap-1.5 font-medium">
                                                <ShieldAlert className="size-3.5 shrink-0" />
                                                <span>Selisih material perlu diselesaikan</span>
                                            </div>
                                            <p>
                                                Ditemukan selisih (rusak, hilang, atau lainnya) saat
                                                gudang memeriksa barang. Atasan yang berwenang harus
                                                memutuskan penyelesaiannya.
                                            </p>
                                        </div>
                                    )}

                                    {returnCase?.status === 'resolved' && (
                                        <div className="rounded-lg border border-success/50 bg-success/10 p-3 text-xs text-emerald-800 dark:text-emerald-300">
                                            <div className="mb-1 flex items-center gap-1.5 font-medium">
                                                <CheckCircle2 className="size-3.5 shrink-0" />
                                                <span>Selisih material sudah diselesaikan</span>
                                            </div>
                                            {returnCase.resolution_note && (
                                                <p>
                                                    Catatan Penyelesaian:{' '}
                                                    <strong>{returnCase.resolution_note}</strong>
                                                </p>
                                            )}
                                        </div>
                                    )}

                                    {returnLoading ? (
                                        <div className="py-6 text-center text-xs text-muted-foreground">
                                            Memuat status pengembalian material...
                                        </div>
                                    ) : !returnCase ? (
                                        <div className="py-6 text-center text-xs text-muted-foreground">
                                            Belum ada berkas pengembalian terhubung dengan
                                            permintaan ini.
                                        </div>
                                    ) : (
                                        <div className="space-y-4">
                                            <Table>
                                                <THead>
                                                    <TR>
                                                        <TH>Barang</TH>
                                                        <TH className="text-right">Dikeluarkan</TH>
                                                        <TH className="text-right">Dipakai Riil</TH>
                                                        <TH className="text-right">
                                                            Sisa Diharapkan
                                                        </TH>
                                                        <TH className="text-right">
                                                            Diterima Fisik
                                                        </TH>
                                                        <TH className="text-right">
                                                            Rusak / Hilang
                                                        </TH>
                                                    </TR>
                                                </THead>
                                                <TBody>
                                                    {returnCase.lines?.map((line) => {
                                                        const unit = line.product?.unit ?? '';
                                                        const expectedReturn = Math.max(
                                                            0,
                                                            line.issued_qty - line.actual_used_qty,
                                                        );
                                                        return (
                                                            <TR key={line.id}>
                                                                <TD className="text-xs font-medium">
                                                                    {line.product?.name ?? '-'}
                                                                </TD>
                                                                <TD className="text-right text-xs">
                                                                    {formatNumber(line.issued_qty)}{' '}
                                                                    {unit}
                                                                </TD>
                                                                <TD className="text-right text-xs font-semibold text-foreground">
                                                                    {formatNumber(
                                                                        line.actual_used_qty,
                                                                    )}{' '}
                                                                    {unit}
                                                                </TD>
                                                                <TD className="text-right text-xs font-semibold text-brand-600 dark:text-brand-400">
                                                                    {formatNumber(
                                                                        line.declared_good_return_qty >
                                                                            0
                                                                            ? line.declared_good_return_qty
                                                                            : expectedReturn,
                                                                    )}{' '}
                                                                    {unit}
                                                                </TD>
                                                                <TD className="text-right text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                                                                    {formatNumber(
                                                                        line.accepted_good_return_qty,
                                                                    )}{' '}
                                                                    {unit}
                                                                </TD>
                                                                <TD className="text-right text-xs text-destructive-text">
                                                                    {line.damaged_return_qty +
                                                                        line.lost_qty >
                                                                    0
                                                                        ? `${formatNumber(line.damaged_return_qty + line.lost_qty)} ${unit}`
                                                                        : '0'}
                                                                </TD>
                                                            </TR>
                                                        );
                                                    })}
                                                </TBody>
                                            </Table>

                                            {/* Action buttons for return custody: Server-authoritative capability filtered */}
                                            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-2">
                                                {/* Technician Declaration Button */}
                                                {canDeclareReturn && (
                                                    <Button
                                                        size="sm"
                                                        variant="primary"
                                                        onClick={() => {
                                                            setActionError(null);
                                                            setDialogOpen('declare');
                                                        }}
                                                        disabled={actionLoading}
                                                        className="gap-1.5"
                                                    >
                                                        <FileText className="h-4 w-4" />
                                                        Laporkan Pemakaian & Ajukan Pengembalian
                                                    </Button>
                                                )}

                                                {/* Warehouse Accept/Reject Buttons */}
                                                {canReceiveReturn && (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            variant="destructive"
                                                            onClick={() => {
                                                                setActionError(null);
                                                                setDialogOpen('receiveReject');
                                                            }}
                                                            disabled={actionLoading}
                                                            className="gap-1.5"
                                                        >
                                                            <XCircle className="h-4 w-4" />
                                                            Tolak Fisik Barang
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => {
                                                                setActionError(null);
                                                                setDialogOpen('receiveAccept');
                                                            }}
                                                            disabled={actionLoading}
                                                            className="gap-1.5"
                                                        >
                                                            <CheckCircle2 className="h-4 w-4" />
                                                            Terima Pengembalian Fisik
                                                        </Button>
                                                    </>
                                                )}

                                                {/* Resolver Resolve Exception Button */}
                                                {canResolveReturn && (
                                                    <Button
                                                        size="sm"
                                                        variant="primary"
                                                        onClick={() => {
                                                            setActionError(null);
                                                            setDialogOpen('resolve');
                                                        }}
                                                        disabled={actionLoading}
                                                        className="gap-1.5"
                                                    >
                                                        <CheckCircle2 className="h-4 w-4" />
                                                        Selesaikan Selisih
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Metadata Sidebar */}
                    <div className="space-y-6">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Detail Permohonan</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-xs">
                                <div>
                                    <span className="block text-muted-foreground">Status:</span>
                                    <div className="mt-1">
                                        <EnumBadge
                                            map={INVENTORY_REQUEST_STATUS}
                                            value={r.status}
                                        />
                                    </div>
                                </div>

                                <div>
                                    <span className="block text-muted-foreground">
                                        Gudang Penyedia:
                                    </span>
                                    <span className="font-medium text-foreground">
                                        {r.warehouse_location?.name ?? <EmptyCell />}
                                    </span>
                                </div>

                                <div>
                                    <span className="block text-muted-foreground">
                                        Tanggal Dibutuhkan:
                                    </span>
                                    <span className="font-medium text-foreground">
                                        {r.needed_at ? formatDate(r.needed_at, company) : '-'}
                                    </span>
                                </div>

                                {r.source_type && r.source_id && (
                                    <div>
                                        <span className="block text-muted-foreground">
                                            Rujukan:
                                        </span>
                                        <span className="rounded bg-muted/60 px-1.5 py-0.5 font-mono font-medium text-foreground">
                                            {labelOf(INVENTORY_REQUEST_SOURCE, r.source_type)} #
                                            {r.source_id}
                                        </span>
                                    </div>
                                )}

                                <div className="border-t border-border pt-2">
                                    <span className="mb-1 block text-muted-foreground">
                                        Alasan Permintaan:
                                    </span>
                                    <p className="rounded bg-muted/20 p-2 leading-relaxed text-foreground italic">
                                        &ldquo;{r.reason}&rdquo;
                                    </p>
                                </div>

                                {r.approval_note && (
                                    <div className="border-t border-border pt-2">
                                        <span className="mb-1 block text-muted-foreground">
                                            Catatan Persetujuan:
                                        </span>
                                        <p className="rounded bg-brand-500/10 p-2 leading-relaxed text-foreground">
                                            &ldquo;{r.approval_note}&rdquo;
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* ACTION DIALOGS */}

                {/* 1. Submit Dialog */}
                <AlertDialog
                    open={dialogOpen === 'submit'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Ajukan Permintaan ke Antrean Gudang?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Permintaan barang {r.code} akan masuk ke antrean petugas gudang
                                untuk ditinjau dan disetujui.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    handleAction(route('admin.inventory.requests.submit', r.id));
                                }}
                                disabled={actionLoading}
                            >
                                {actionLoading ? 'Mengajukan...' : 'Ya, Ajukan'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* 2. Approve Dialog */}
                <AlertDialog
                    open={dialogOpen === 'approve'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent className="max-w-lg">
                        <AlertDialogHeader>
                            <AlertDialogTitle>Setujui Permintaan & Reservasi Stok</AlertDialogTitle>
                            <AlertDialogDescription>
                                Menyetujui permintaan ini akan secara otomatis mengunci
                                (mereservasi) stok di gudang agar tidak dipakai pekerjaan lain.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <div className="space-y-4 py-2 text-xs">
                            <div className="space-y-2">
                                <Label>Jumlah yang disetujui</Label>
                                {items.map((item) => {
                                    const unit = item.product?.unit ?? '';
                                    const available = stockAvailable?.[item.id];
                                    const approved =
                                        approvedQtys[item.id] ?? item.quantity_requested;

                                    return (
                                        <div
                                            key={item.id}
                                            className="flex items-start justify-between gap-3 rounded border border-border p-2"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium break-words">
                                                    {item.product?.name}
                                                </p>
                                                {available !== undefined && (
                                                    <p className="text-muted-foreground">
                                                        Tersedia: {formatNumber(available)} {unit}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="w-36 shrink-0">
                                                <Input
                                                    id={`approve-qty-${item.id}`}
                                                    aria-label={`Jumlah disetujui untuk ${item.product?.name ?? 'barang'}`}
                                                    type="number"
                                                    step="any"
                                                    min="0"
                                                    value={approved}
                                                    onChange={(e) =>
                                                        setApprovedQtys({
                                                            ...approvedQtys,
                                                            [item.id]:
                                                                parseFloat(e.target.value) || 0,
                                                        })
                                                    }
                                                    error={
                                                        overStock(item.id, approved)
                                                            ? 'Melebihi stok tersedia'
                                                            : undefined
                                                    }
                                                    className="text-right text-xs"
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                            {approveBlocked && (
                                <p
                                    id="approve-blocked"
                                    role="status"
                                    className="text-sm text-destructive-text"
                                >
                                    Kurangi jumlah yang melebihi stok.
                                </p>
                            )}
                            <div className="space-y-1">
                                <Label htmlFor="approval_note">
                                    Catatan Persetujuan (opsional)
                                </Label>
                                <Textarea
                                    id="approval_note"
                                    rows={2}
                                    placeholder="Catatan untuk pemohon, jika ada"
                                    value={approveNote}
                                    onChange={(e) => setApproveNote(e.target.value)}
                                />
                            </div>
                        </div>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    const payloadItems = items.map((item) => ({
                                        id: item.id,
                                        quantity_approved:
                                            approvedQtys[item.id] ?? item.quantity_requested,
                                    }));
                                    handleAction(route('admin.inventory.requests.approve', r.id), {
                                        approval_note: approveNote,
                                        items: payloadItems,
                                    });
                                }}
                                disabled={actionLoading || approveBlocked}
                                aria-describedby={approveBlocked ? 'approve-blocked' : undefined}
                            >
                                {actionLoading ? 'Memproses...' : 'Setujui & Reservasi'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* 3. Reject Dialog */}
                <AlertDialog
                    open={dialogOpen === 'reject'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Tolak Permintaan Barang</AlertDialogTitle>
                            <AlertDialogDescription>
                                Anda wajib memberikan alasan yang jelas atas penolakan permintaan
                                ini kepada pemohon.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <div className="py-2">
                            <Label htmlFor="rejection_reason">Alasan Penolakan *</Label>
                            <Textarea
                                id="rejection_reason"
                                rows={3}
                                placeholder="Jelaskan alasan penolakan kepada pemohon"
                                value={rejectReason}
                                onChange={(e) => setRejectReason(e.target.value)}
                                required
                            />
                        </div>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    if (rejectReason.trim().length < 3) {
                                        setActionError(
                                            'Harap isi alasan penolakan minimal 3 karakter.',
                                        );
                                        return;
                                    }
                                    handleAction(route('admin.inventory.requests.reject', r.id), {
                                        rejection_reason: rejectReason,
                                    });
                                }}
                                disabled={actionLoading || rejectReason.trim().length < 3}
                                className={buttonVariants({ variant: 'destructive' })}
                            >
                                {actionLoading ? 'Menolak...' : 'Tolak Permintaan'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* 4. Prepare Dialog */}
                <AlertDialog
                    open={dialogOpen === 'prepare'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Mulai Penyiapan Barang di Gudang?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Status permintaan akan berubah menjadi &ldquo;
                                {labelOf(INVENTORY_REQUEST_STATUS, 'preparing')}&rdquo; agar teknisi
                                tahu barang sedang dikumpulkan.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    handleAction(route('admin.inventory.requests.prepare', r.id));
                                }}
                                disabled={actionLoading}
                            >
                                {actionLoading ? 'Memproses...' : 'Ya, Mulai Penyiapan'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* 5. Ready Dialog */}
                <AlertDialog
                    open={dialogOpen === 'ready'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Tandai Barang Siap Diambil?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Notifikasi akan dikirimkan kepada teknisi/pemohon bahwa barang fisik
                                telah siap di meja serah-terima gudang.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    handleAction(route('admin.inventory.requests.ready', r.id));
                                }}
                                disabled={actionLoading}
                            >
                                {actionLoading ? 'Memproses...' : 'Tandai Siap Diambil'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* 6. Issue Dialog */}
                <AlertDialog
                    open={dialogOpen === 'issue'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Konfirmasi serah terima barang?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Pastikan teknisi pemohon telah memeriksa dan menerima barang fisik.
                                Aksi ini akan{' '}
                                <strong>secara permanen mengurangi stok gudang</strong> dan membuat
                                berkas pertanggungjawaban pengembalian material.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    handleAction(route('admin.inventory.requests.issue', r.id));
                                }}
                                disabled={actionLoading}
                            >
                                {actionLoading ? 'Menyerahkan...' : 'Konfirmasi Serah Terima'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* 7. Cancel Dialog */}
                <AlertDialog
                    open={dialogOpen === 'cancel'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Batalkan Permintaan Barang?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Pembatalan hanya dapat dilakukan jika barang belum diserahterimakan.
                                Semua reservasi stok akan dilepaskan kembali ke gudang.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <div className="py-2">
                            <Label htmlFor="cancellation_reason">
                                Alasan Pembatalan (opsional)
                            </Label>
                            <Textarea
                                id="cancellation_reason"
                                rows={2}
                                placeholder="Jelaskan alasan pembatalan, jika ada"
                                value={cancelReason}
                                onChange={(e) => setCancelReason(e.target.value)}
                            />
                        </div>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Tutup</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    handleAction(route('admin.inventory.requests.cancel', r.id), {
                                        cancellation_reason: cancelReason,
                                    });
                                }}
                                disabled={actionLoading}
                                className={buttonVariants({ variant: 'destructive' })}
                            >
                                {actionLoading ? 'Membatalkan...' : 'Batalkan Permintaan'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* 8. Technician Declare Dialog */}
                <AlertDialog
                    open={dialogOpen === 'declare'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent className="max-w-xl">
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Deklarasi Pemakaian Aktual & Sisa Barang
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Masukkan jumlah barang yang riil digunakan di lapangan. Selisih akan
                                otomatis dihitung sebagai sisa yang harus dikembalikan ke gudang.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <div className="space-y-4 py-2 text-xs">
                            {declareLines.map((line, idx) => (
                                <div
                                    key={line.id}
                                    className="space-y-2 rounded-lg border border-border bg-card/50 p-3"
                                >
                                    <div className="flex justify-between font-semibold">
                                        <span>{line.product_name ?? 'Barang'}</span>
                                        <span>Dikeluarkan: {formatNumber(line.issued_qty)}</span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <div>
                                            <Label className="text-xs">Dipakai Aktual</Label>
                                            <Input
                                                type="number"
                                                step="any"
                                                min="0"
                                                max={line.issued_qty}
                                                value={line.actual_used_qty}
                                                onChange={(e) => {
                                                    const val = parseFloat(e.target.value) || 0;
                                                    const newLines = [...declareLines];
                                                    newLines[idx].actual_used_qty = val;
                                                    newLines[idx].declared_good_return_qty =
                                                        Math.max(0, line.issued_qty - val);
                                                    setDeclareLines(newLines);
                                                    if (actionError) setActionError(null);
                                                }}
                                                className="h-8 text-xs"
                                            />
                                        </div>
                                        <div>
                                            <Label className="text-xs">Sisa Dikembalikan</Label>
                                            <Input
                                                type="number"
                                                step="any"
                                                min="0"
                                                value={line.declared_good_return_qty}
                                                onChange={(e) => {
                                                    const val = parseFloat(e.target.value) || 0;
                                                    const newLines = [...declareLines];
                                                    newLines[idx].declared_good_return_qty = val;
                                                    setDeclareLines(newLines);
                                                    if (actionError) setActionError(null);
                                                }}
                                                className="h-8 text-xs"
                                            />
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    if (!returnCase) return;
                                    const payload = {
                                        lines: declareLines.map((l) => ({
                                            id: l.id,
                                            actual_used_qty: l.actual_used_qty,
                                            declared_good_return_qty: l.declared_good_return_qty,
                                            declared_discrepancy_qty:
                                                l.declared_discrepancy_qty || 0,
                                            discrepancy_code: l.discrepancy_code || null,
                                            discrepancy_reason: l.discrepancy_reason || null,
                                        })),
                                    };
                                    handleReturnApiAction(
                                        route('admin.inventory.returns.declare', returnCase.id),
                                        payload,
                                    );
                                }}
                                disabled={actionLoading}
                            >
                                {actionLoading ? 'Menyimpan...' : 'Kirim Laporan Deklarasi'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* 9. Warehouse Physical Accept Dialog */}
                <AlertDialog
                    open={dialogOpen === 'receiveAccept'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent className="max-w-xl">
                        <AlertDialogHeader>
                            <AlertDialogTitle>Terima Pengembalian Fisik Material</AlertDialogTitle>
                            <AlertDialogDescription>
                                Periksa fisik barang yang dibawa teknisi. Jumlah barang dalam
                                kondisi baik akan{' '}
                                <strong>secara atomik ditambahkan kembali ke stok gudang</strong>.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <div className="space-y-4 py-2 text-xs">
                            {acceptLines.map((line, idx) => {
                                const gap = reconcileGap(line);

                                return (
                                    <div
                                        key={line.id}
                                        className="space-y-3 rounded-lg border border-border bg-card/50 p-3"
                                    >
                                        <div className="space-y-0.5">
                                            <p className="font-semibold">
                                                {line.product_name ?? 'Barang'}
                                            </p>
                                            <p className="text-muted-foreground">
                                                Diserahkan {formatNumber(line.issued_qty)} ·
                                                terpakai {formatNumber(line.actual_used_qty)}
                                            </p>
                                        </div>
                                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                            {ACCEPT_FIELDS.map(({ key, label }) => (
                                                <Input
                                                    key={key}
                                                    id={`accept-${line.id}-${key}`}
                                                    label={label}
                                                    aria-label={`${label}, ${line.product_name ?? 'barang'}`}
                                                    type="number"
                                                    step="any"
                                                    min="0"
                                                    value={line[key]}
                                                    onChange={(e) =>
                                                        updateAcceptLine(idx, key, e.target.value)
                                                    }
                                                    className="text-xs"
                                                />
                                            ))}
                                        </div>
                                        <p
                                            role="status"
                                            className={`flex items-center gap-1.5 text-sm ${
                                                gap === 0
                                                    ? 'text-(--status-success)'
                                                    : 'text-destructive-text'
                                            }`}
                                        >
                                            {gap === 0 ? (
                                                <CheckCircle2
                                                    className="size-4 shrink-0"
                                                    aria-hidden="true"
                                                />
                                            ) : (
                                                <AlertCircle
                                                    className="size-4 shrink-0"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {gap === 0
                                                ? 'Jumlah cocok'
                                                : gap > 0
                                                  ? `Kurang ${formatNumber(gap)}`
                                                  : `Lebih ${formatNumber(-gap)}`}
                                        </p>
                                    </div>
                                );
                            })}
                            {acceptBlocked && (
                                <p
                                    id="accept-blocked"
                                    role="status"
                                    className="text-sm text-destructive-text"
                                >
                                    Samakan jumlah setiap barang dengan yang diserahkan.
                                </p>
                            )}
                        </div>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    if (!returnCase) return;
                                    const payload = {
                                        lines: acceptLines.map((l) => ({
                                            id: l.id,
                                            accepted_good_return_qty: l.accepted_good_return_qty,
                                            damaged_return_qty: l.damaged_return_qty,
                                            lost_qty: l.lost_qty,
                                            other_discrepancy_qty: l.other_discrepancy_qty,
                                            // One kind per line is stored: the first column that holds a quantity. The reason
                                            // the technician gave stays as it is.
                                            discrepancy_code:
                                                l.damaged_return_qty > 0
                                                    ? 'damaged'
                                                    : l.lost_qty > 0
                                                      ? 'lost'
                                                      : l.other_discrepancy_qty > 0
                                                        ? 'other'
                                                        : null,
                                        })),
                                    };
                                    handleReturnApiAction(
                                        route(
                                            'admin.inventory.returns.receive-accept',
                                            returnCase.id,
                                        ),
                                        payload,
                                    );
                                }}
                                disabled={actionLoading || acceptBlocked}
                                aria-describedby={acceptBlocked ? 'accept-blocked' : undefined}
                            >
                                {actionLoading ? 'Memproses...' : 'Konfirmasi Penerimaan Fisik'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* 10. Warehouse Physical Reject Dialog */}
                <AlertDialog
                    open={dialogOpen === 'receiveReject'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Tolak Penyerahan Fisik Barang</AlertDialogTitle>
                            <AlertDialogDescription>
                                Tolak penerimaan fisik jika barang belum diserahkan ke meja gudang
                                atau tidak sesuai deklarasi. Status akan berubah menjadi &ldquo;
                                {labelOf(MATERIAL_RETURN_STATUS, 'correction_required')}&rdquo; dan
                                stok tidak berubah.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <div className="py-2">
                            <Label htmlFor="return_reject_reason">Alasan Penolakan Fisik *</Label>
                            <Textarea
                                id="return_reject_reason"
                                rows={3}
                                placeholder="Jelaskan mengapa penyerahan fisik ditolak"
                                value={returnRejectReason}
                                onChange={(e) => {
                                    setReturnRejectReason(e.target.value);
                                    if (actionError) setActionError(null);
                                }}
                                required
                            />
                        </div>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    if (!returnCase) return;
                                    if (returnRejectReason.trim().length < 3) {
                                        setActionError(
                                            'Harap isi alasan penolakan minimal 3 karakter.',
                                        );
                                        return;
                                    }
                                    handleReturnApiAction(
                                        route(
                                            'admin.inventory.returns.receive-reject',
                                            returnCase.id,
                                        ),
                                        {
                                            reason: returnRejectReason.trim(),
                                        },
                                    );
                                }}
                                disabled={actionLoading || returnRejectReason.trim().length < 3}
                                className={buttonVariants({ variant: 'destructive' })}
                            >
                                {actionLoading ? 'Menolak...' : 'Tolak Penyerahan'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* 11. Resolver Resolve Exception Dialog */}
                <AlertDialog
                    open={dialogOpen === 'resolve'}
                    onOpenChange={(open) => !open && setDialogOpen(null)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Selesaikan Selisih Material</AlertDialogTitle>
                            <AlertDialogDescription>
                                Tinjau selisih, kerusakan, atau kehilangan pada pengembalian
                                material. Tulis catatan keputusan penyelesaian untuk menutup kasus
                                ini.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {actionError && (
                            <div
                                role="alert"
                                aria-live="assertive"
                                className="mb-2 rounded border border-destructive/50 bg-destructive/10 p-2.5 text-xs text-destructive-text"
                            >
                                {actionError}
                            </div>
                        )}
                        <div className="py-2">
                            <Label htmlFor="resolution_note">Catatan Penyelesaian *</Label>
                            <Textarea
                                id="resolution_note"
                                rows={3}
                                placeholder="Jelaskan keputusan penyelesaian selisih"
                                value={resolutionNote}
                                onChange={(e) => {
                                    setResolutionNote(e.target.value);
                                    if (actionError) setActionError(null);
                                }}
                                required
                            />
                        </div>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={actionLoading}>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    if (!returnCase) return;
                                    if (resolutionNote.trim().length < 3) {
                                        setActionError('Catatan penyelesaian minimal 3 karakter.');
                                        return;
                                    }
                                    handleReturnApiAction(
                                        route('admin.inventory.returns.resolve', returnCase.id),
                                        {
                                            resolution_note: resolutionNote.trim(),
                                        },
                                    );
                                }}
                                disabled={actionLoading || resolutionNote.trim().length < 3}
                            >
                                {actionLoading ? 'Menyelesaikan...' : 'Konfirmasi Penyelesaian'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AdminLayout>
    );
}
