import type { FormEvent } from 'react';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    EnumBadge,
    Input,
    Modal,
    Switch,
    Table,
    TBody,
    TD,
    TH,
    THead,
    Textarea,
    TR,
} from '@/Components/ui';
import { ACTIVE_STATE } from '@/lib/statuses';

const CUSTOMER_HINT_MAX = 500;

interface CategoryRow {
    id: string;
    code: string;
    name: string;
    default_sla_hours: number;
    default_response_hours: number;
    is_active: boolean;
    customer_hint: string | null;
}

interface Props extends Record<string, unknown> {
    categories: CategoryRow[];
    can: { manage: boolean };
}

export default function TicketCategoriesIndex({ categories, can }: Props) {
    const [editing, setEditing] = useState<CategoryRow | null>(null);
    const { data, setData, put, processing, errors, clearErrors, reset } = useForm({
        name: '',
        default_sla_hours: '',
        default_response_hours: '',
        is_active: true,
        customer_hint: '',
    });

    const open = (category: CategoryRow) => {
        clearErrors();
        setData({
            name: category.name,
            default_sla_hours: String(category.default_sla_hours),
            default_response_hours: String(category.default_response_hours),
            is_active: category.is_active,
            customer_hint: category.customer_hint ?? '',
        });
        setEditing(category);
    };

    const close = () => {
        setEditing(null);
        clearErrors();
        reset();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!editing) return;
        put(route('admin.ticket-categories.update', editing.id), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    return (
        <AdminLayout title="Kategori Tiket">
            <div className="space-y-6">
                <PageHeader
                    title="Kategori Tiket"
                    subtitle="Target cadangan untuk tiket tanpa langganan atau tanpa tingkat SLA aktif. Tiket urgent memakai setengah dari target (dibulatkan ke atas)."
                />
                <Card>
                    <CardContent className="pt-6">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Kode</TH>
                                    <TH>Nama</TH>
                                    <TH className="text-right">Target respons (jam)</TH>
                                    <TH className="text-right">Target penyelesaian (jam)</TH>
                                    <TH>Status</TH>
                                    {can.manage && <TH>Aksi</TH>}
                                </TR>
                            </THead>
                            <TBody>
                                {categories.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={can.manage ? 6 : 5}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            Belum ada kategori tiket untuk perusahaan ini.
                                        </TD>
                                    </TR>
                                ) : (
                                    categories.map((category) => (
                                        <TR key={category.id}>
                                            <TD className="font-mono text-xs">{category.code}</TD>
                                            <TD className="font-medium">{category.name}</TD>
                                            <TD className="text-right tabular-nums">
                                                {category.default_response_hours}
                                            </TD>
                                            <TD className="text-right tabular-nums">
                                                {category.default_sla_hours}
                                            </TD>
                                            <TD>
                                                <EnumBadge
                                                    map={ACTIVE_STATE}
                                                    value={category.is_active}
                                                />
                                            </TD>
                                            {can.manage && (
                                                <TD>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => open(category)}
                                                        aria-label={`Ubah kategori ${category.name}`}
                                                    >
                                                        Ubah
                                                    </Button>
                                                </TD>
                                            )}
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <Modal
                open={editing !== null}
                onClose={close}
                title={editing ? `Ubah kategori ${editing.code}` : undefined}
            >
                <form onSubmit={submit} className="space-y-4">
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
                        value={data.default_response_hours}
                        onChange={(e) => setData('default_response_hours', e.target.value)}
                        error={errors.default_response_hours}
                        hint="Tidak boleh melebihi target penyelesaian."
                        required
                    />
                    <Input
                        label="Target penyelesaian (jam)"
                        type="number"
                        min={1}
                        max={720}
                        value={data.default_sla_hours}
                        onChange={(e) => setData('default_sla_hours', e.target.value)}
                        error={errors.default_sla_hours}
                        hint="Hanya berlaku untuk tiket baru."
                        required
                    />
                    <Switch
                        label="Aktif"
                        checked={data.is_active}
                        onCheckedChange={(checked) => setData('is_active', checked)}
                    />
                    <Textarea
                        label="Saran untuk pelanggan"
                        value={data.customer_hint}
                        onChange={(e) => setData('customer_hint', e.target.value)}
                        maxLength={CUSTOMER_HINT_MAX}
                        rows={4}
                        hint={`Tampil di aplikasi pelanggan saat memilih kategori ini. Kosongkan jika tidak perlu. ${data.customer_hint.length}/${CUSTOMER_HINT_MAX} karakter.`}
                        error={errors.customer_hint}
                    />
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={close}>
                            Batal
                        </Button>
                        <Button type="submit" loading={processing}>
                            Simpan
                        </Button>
                    </div>
                </form>
            </Modal>
        </AdminLayout>
    );
}
