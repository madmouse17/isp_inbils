import type { FormEvent } from 'react';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    PhoneInput,
    Switch,
    Textarea,
} from '@/Components/ui';
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
import { buttonVariants } from '@/Components/ui/Button';
import { Spinner } from '@/Components/ui/Spinner';
import { cn } from '@/lib/utils';
import type { CustomerContact } from '@/types/models';

interface CustomerContactEditorProps {
    customerId: string;
    contacts: CustomerContact[];
    canManage: boolean;
}

type ContactForm = {
    id: string | null;
    name: string;
    position: string;
    phone: string;
    email: string;
    is_primary: boolean;
    notes: string;
};

const emptyContact = (): ContactForm => ({
    id: null,
    name: '',
    position: '',
    phone: '',
    email: '',
    is_primary: false,
    notes: '',
});

export function CustomerContactEditor({
    customerId,
    contacts,
    canManage,
}: CustomerContactEditorProps) {
    const {
        data,
        setData,
        post,
        put,
        delete: destroy,
        processing,
        errors,
        reset,
    } = useForm<ContactForm>(emptyContact());

    // Editing state lives outside the Inertia form so it survives partial reloads.
    const [editingId, setEditingId] = useState<string | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<CustomerContact | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const startCreate = () => {
        setEditingId(null);
        reset();
    };

    const startEdit = (row: CustomerContact) => {
        setEditingId(row.id);
        setData('id', row.id);
        setData('name', row.name ?? '');
        setData('position', row.position ?? '');
        setData('phone', row.phone ?? '');
        setData('email', row.email ?? '');
        setData('is_primary', Boolean(row.is_primary));
        setData('notes', row.notes ?? '');
    };

    const cancelEdit = () => {
        setEditingId(null);
        reset();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (processing) return;

        if (editingId) {
            put(route('admin.customers.contacts.update', [customerId, editingId]), {
                preserveScroll: true,
                onSuccess: () => cancelEdit(),
            });
        } else {
            post(route('admin.customers.contacts.store', customerId), {
                preserveScroll: true,
                onSuccess: () => cancelEdit(),
            });
        }
    };

    const confirmDelete = () => {
        if (!deleteTarget) return;
        if (processing || deleteProcessing) return;
        setDeleteProcessing(true);
        destroy(route('admin.customers.contacts.destroy', [customerId, deleteTarget.id]), {
            preserveScroll: true,
            onFinish: () => setDeleteProcessing(false),
            onSuccess: () => {
                setDeleteTarget(null);
                setDeleteProcessing(false);
            },
        });
    };

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader className="space-y-0 flex-row flex-wrap items-center justify-between">
                    <CardTitle>Kontak</CardTitle>
                    {canManage ? (
                        <Button
                            type="button"
                            variant="primary"
                            size="sm"
                            onClick={startCreate}
                            disabled={processing}
                        >
                            Tambah kontak
                        </Button>
                    ) : null}
                </CardHeader>
                <CardContent className="space-y-3">
                    {contacts.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            {canManage
                                ? 'Belum ada kontak. Tambahkan kontak pertama lewat formulir di bawah.'
                                : 'Belum ada kontak.'}
                        </p>
                    ) : (
                        contacts.map((row) => (
                            <div
                                key={row.id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
                            >
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        {row.name}
                                        {row.is_primary ? (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                (utama)
                                            </span>
                                        ) : null}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {[row.position, row.phone, row.email]
                                            .filter(Boolean)
                                            .join(', ') || '-'}
                                    </p>
                                </div>
                                {canManage ? (
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => startEdit(row)}
                                            disabled={processing}
                                        >
                                            Ubah
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="danger"
                                            size="sm"
                                            onClick={() => setDeleteTarget(row)}
                                            disabled={processing}
                                        >
                                            Hapus
                                        </Button>
                                    </div>
                                ) : null}
                            </div>
                        ))
                    )}
                </CardContent>
            </Card>

            {canManage ? (
                <Card>
                    <CardHeader>
                        <CardTitle>{editingId ? 'Ubah Kontak' : 'Tambah Kontak'}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4" aria-busy={processing}>
                            <div className="grid gap-4 md:grid-cols-2">
                                <Input
                                    label="Nama"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    error={errors.name}
                                    required
                                />
                                <Input
                                    label="Jabatan"
                                    value={data.position}
                                    onChange={(e) => setData('position', e.target.value)}
                                    error={errors.position}
                                />
                                <PhoneInput
                                    label="Telepon"
                                    value={data.phone}
                                    onChange={(value) => setData('phone', value)}
                                    error={errors.phone}
                                    required
                                />
                                <Input
                                    label="Email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    error={errors.email}
                                />
                            </div>
                            <Switch
                                label="Kontak utama"
                                checked={data.is_primary}
                                onCheckedChange={(checked) => setData('is_primary', checked)}
                            />
                            <Textarea
                                label="Catatan"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                error={errors.notes}
                                rows={2}
                            />
                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={cancelEdit}
                                    disabled={processing}
                                >
                                    Batal
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    loading={processing}
                                    disabled={processing}
                                >
                                    {editingId ? 'Simpan' : 'Tambah'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            ) : null}

            <AlertDialog
                open={deleteTarget !== null}
                onOpenChange={(open) => {
                    if (!open && !deleteProcessing) setDeleteTarget(null);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Hapus kontak {deleteTarget?.name ? `"${deleteTarget.name}"` : ''}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Kontak ini akan dihapus permanen dari pelanggan.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleteProcessing}>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            className={cn(buttonVariants({ variant: 'destructive' }), 'gap-2')}
                            onClick={(event) => {
                                event.preventDefault();
                                confirmDelete();
                            }}
                            disabled={deleteProcessing}
                        >
                            {deleteProcessing ? <Spinner size="sm" /> : null}
                            Hapus
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
