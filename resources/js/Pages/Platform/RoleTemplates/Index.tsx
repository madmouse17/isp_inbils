import { useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import { ConfirmActionDialog } from '@/Components/composite/platform/ConfirmActionDialog';
import { Button, Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui';
import { formatNumber } from '@/lib/format';
import type { PlatformRoleTemplateRow } from '@/types/platform';

interface IndexProps {
    templates: { data: PlatformRoleTemplateRow[] };
}

export default function Index({ templates }: IndexProps) {
    const [deleting, setDeleting] = useState<PlatformRoleTemplateRow | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const [deleteErrors, setDeleteErrors] = useState<Record<string, string | undefined>>({});
    const deleteInFlight = useRef(false);
    const createButton = (
        <Button asChild leftIcon={<Plus className="size-4" aria-hidden="true" />}>
            <Link href={route('platform.role-templates.create')}>Buat templat</Link>
        </Button>
    );

    const removeTemplate = () => {
        if (!deleting || deleteInFlight.current) return;

        deleteInFlight.current = true;
        setDeleteProcessing(true);
        setDeleteErrors({});
        router.delete(route('platform.role-templates.destroy', deleting.id), {
            preserveScroll: true,
            onError: (errors) => setDeleteErrors(errors as Record<string, string | undefined>),
            onSuccess: () => setDeleting(null),
            onFinish: () => {
                deleteInFlight.current = false;
                setDeleteProcessing(false);
            },
        });
    };

    const columns = useMemo<DataTableColumn<PlatformRoleTemplateRow>[]>(
        () => [
            {
                key: 'name',
                header: 'Nama',
                cell: (template) => (
                    <div>
                        <Link
                            href={route('platform.role-templates.edit', template.id)}
                            className="font-medium text-primary-text hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                        >
                            {template.name}
                        </Link>
                        <p className="text-xs text-muted-foreground">Kunci {template.key}</p>
                    </div>
                ),
            },
            {
                key: 'permissions_count',
                header: 'Hak akses',
                className: 'text-right tabular-nums',
                cell: (template) => formatNumber(template.permissions_count),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (template) => (
                    <div className="flex items-center gap-1">
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('platform.role-templates.edit', template.id)}>
                                Ubah
                            </Link>
                        </Button>
                        {template.deletable ? (
                            <Button
                                type="button"
                                variant="destructive-outline"
                                size="sm"
                                leftIcon={<Trash2 className="size-4" aria-hidden="true" />}
                                onClick={() => setDeleting(template)}
                            >
                                Hapus
                            </Button>
                        ) : null}
                    </div>
                ),
            },
        ],
        [],
    );

    return (
        <PlatformLayout title="Templat peran">
            <PageHeader
                title="Templat peran"
                subtitle="Titik awal peran untuk perusahaan baru. Setiap perusahaan memiliki salinannya sendiri."
                actions={createButton}
            />

            <Card>
                <CardHeader>
                    <CardTitle>Templat</CardTitle>
                    <CardDescription>
                        Perubahan templat hanya berlaku untuk perusahaan baru; peran perusahaan yang
                        sudah ada tidak berubah. Templat Admin dan Pelanggan tidak dapat dihapus.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={templates.data}
                        emptyTitle="Belum ada templat"
                        emptyDescription="Buat templat agar perusahaan baru mendapat peran siap pakai."
                        emptyAction={createButton}
                        itemLabel="templat"
                    />
                </CardContent>
            </Card>

            {deleting ? (
                <ConfirmActionDialog
                    title={`Hapus templat ${deleting.name}?`}
                    description="Perusahaan baru tidak lagi mendapat peran ini. Peran yang sudah disalin ke perusahaan tidak berubah."
                    confirmLabel="Hapus templat"
                    tone="danger"
                    processing={deleteProcessing}
                    errors={deleteErrors}
                    onConfirmed={removeTemplate}
                    onClose={() => setDeleting(null)}
                />
            ) : null}
        </PlatformLayout>
    );
}
