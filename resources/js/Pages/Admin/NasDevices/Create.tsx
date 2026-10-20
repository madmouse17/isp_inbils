import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    Button,
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
    Input,
} from '@/Components/ui';
import { PageHeader, RemoteSearchSelect, type RemoteOption } from '@/Components/composite';
import { RadiusSecretField } from '@/Components/composite/RadiusSecretField';
import { RouterOsCommandCard } from '@/Components/composite/RouterOsCommandCard';

interface CreateProps extends Record<string, unknown> {
    /** Sent on a search only (`routerAsset_search`). An option's description is the router's management IP. */
    routerAssetOptions?: RemoteOption[];
    /** Whether any router asset with a management IP is left to register. */
    hasRouterAssets: boolean;
    /** Lower-cased company code; null when it cannot be used as a RADIUS realm. */
    realm: string | null;
}

export default function Create({ routerAssetOptions, hasRouterAssets, realm }: CreateProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        network_asset_id: '',
        nasname: '',
        coa_port: '1700',
        radius_secret: '',
    });

    // The NAS address is usually the router's management IP, so choosing the router fills it in.
    const selectAsset = (assetId: string) => {
        const option = routerAssetOptions?.find((candidate) => String(candidate.value) === assetId);
        setData((current) => ({
            ...current,
            network_asset_id: assetId,
            nasname: option?.description ?? current.nasname,
        }));
    };

    const realmError = (errors as Record<string, string | undefined>).realm;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.nas-devices.store'), {
            onFinish: () => reset('radius_secret'),
        });
    };

    return (
        <AdminLayout title="Daftarkan Router NAS">
            <div className="space-y-6">
                <PageHeader
                    title="Daftarkan Router NAS"
                    subtitle="Router menjadi klien FreeRADIUS dengan secret bersama tersendiri."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.nas-devices.index')}>Kembali</Link>
                        </Button>
                    }
                />

                {realm === null ? (
                    <Alert variant="warning">
                        <AlertDescription>
                            Kode perusahaan tidak dapat dipakai sebagai realm RADIUS (huruf kecil,
                            angka, dan tanda hubung, maksimal 24 karakter). Ubah kode perusahaan
                            sebelum mendaftarkan router.
                        </AlertDescription>
                    </Alert>
                ) : !hasRouterAssets ? (
                    <Alert variant="info">
                        <AlertDescription className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <span>
                                Belum ada aset router yang tersedia. Tambahkan aset bertipe Router
                                dengan IP manajemen di Aset Jaringan lebih dulu.
                            </span>
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('admin.network-assets.index')}>
                                    Aset Jaringan
                                </Link>
                            </Button>
                        </AlertDescription>
                    </Alert>
                ) : (
                    <form onSubmit={submit}>
                        <Card>
                            <CardHeader>
                                <CardTitle>Router</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <RemoteSearchSelect
                                    field="routerAsset"
                                    label="Aset router"
                                    value={data.network_asset_id}
                                    onChange={selectAsset}
                                    options={routerAssetOptions}
                                    placeholder="Cari kode, nama, atau IP manajemen"
                                    emptyText="Tidak ada aset router yang cocok."
                                    hint="Hanya aset router yang punya IP manajemen dan belum terdaftar."
                                    error={errors.network_asset_id}
                                    required
                                />
                                {realmError ? (
                                    <p role="alert" className="text-sm text-destructive-text">
                                        {realmError}
                                    </p>
                                ) : null}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Input
                                        label="Alamat NAS"
                                        value={data.nasname}
                                        onChange={(event) => setData('nasname', event.target.value)}
                                        hint="Alamat IPv4 yang dilihat FreeRADIUS sebagai asal permintaan router."
                                        error={errors.nasname}
                                        inputMode="decimal"
                                        required
                                    />
                                    <Input
                                        label="Port CoA"
                                        type="number"
                                        min={1}
                                        max={65535}
                                        value={data.coa_port}
                                        onChange={(event) =>
                                            setData('coa_port', event.target.value)
                                        }
                                        hint="Port masuk /radius di MikroTik"
                                        error={errors.coa_port}
                                        required
                                    />
                                </div>
                                <RadiusSecretField
                                    value={data.radius_secret}
                                    onChange={(value) => setData('radius_secret', value)}
                                    hint="Sama dengan secret di router. Disimpan terenkripsi dan tidak pernah ditampilkan lagi oleh Inbils."
                                    error={errors.radius_secret}
                                    required
                                />
                                <Input
                                    label="Realm"
                                    value={realm}
                                    readOnly
                                    hint="Kode perusahaan. Pelanggan masuk sebagai nama@realm."
                                />
                            </CardContent>
                            <CardFooter className="justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => router.get(route('admin.nas-devices.index'))}
                                >
                                    Batal
                                </Button>
                                <Button type="submit" loading={processing} disabled={processing}>
                                    Daftarkan router
                                </Button>
                            </CardFooter>
                        </Card>
                    </form>
                )}

                {realm !== null && hasRouterAssets ? (
                    <RouterOsCommandCard
                        nasAddress={data.nasname}
                        coaPort={Number(data.coa_port)}
                        secret={data.radius_secret}
                    />
                ) : null}
            </div>
        </AdminLayout>
    );
}
