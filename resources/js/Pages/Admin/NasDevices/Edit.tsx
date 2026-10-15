import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
    Input,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import type { NasDevice, RouterAssetOption } from '@/types/network-asset';

interface EditProps extends Record<string, unknown> {
    /** Only the router asset this NAS router is registered on: it cannot change. */
    routerAssets: RouterAssetOption[];
    nasDevice: NasDevice;
}

export default function Edit({ routerAssets, nasDevice }: EditProps) {
    const { data, setData, put, processing, errors, reset } = useForm({
        nasname: nasDevice.nasname,
        coa_port: String(nasDevice.coa_port),
        radius_secret: '',
    });

    const asset = routerAssets[0];
    const assetLabel = asset
        ? `${asset.code} - ${asset.name} (${asset.management_ip ?? 'tanpa IP manajemen'})`
        : (nasDevice.network_asset?.code ?? `Router #${nasDevice.id}`);
    const showHref = route('admin.nas-devices.show', nasDevice.id);

    const realmError = (errors as Record<string, string | undefined>).realm;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.nas-devices.update', nasDevice.id), {
            onSuccess: () => reset('radius_secret'),
        });
    };

    return (
        <AdminLayout title="Ubah Router NAS">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah Router NAS"
                    subtitle="Ubah alamat, port CoA, atau secret bersama yang dipakai FreeRADIUS untuk router ini."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={showHref}>Kembali</Link>
                        </Button>
                    }
                />

                <form onSubmit={submit}>
                    <Card>
                        <CardHeader>
                            <CardTitle>Router</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <dl className="space-y-1.5 text-sm">
                                <dt className="font-medium text-foreground">Aset router</dt>
                                <dd className="text-muted-foreground">{assetLabel}</dd>
                            </dl>
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
                                    onChange={(event) => setData('coa_port', event.target.value)}
                                    hint="Port masuk /radius di MikroTik"
                                    error={errors.coa_port}
                                    required
                                />
                            </div>
                            <Input
                                label="Secret RADIUS"
                                type="password"
                                autoComplete="off"
                                placeholder={nasDevice.has_radius_secret ? 'Tersimpan' : undefined}
                                value={data.radius_secret}
                                onChange={(event) => setData('radius_secret', event.target.value)}
                                hint={
                                    nasDevice.has_radius_secret
                                        ? 'Secret sudah tersimpan. Kosongkan untuk mempertahankannya.'
                                        : 'Disimpan terenkripsi dan tidak pernah ditampilkan lagi oleh Inbils.'
                                }
                                error={errors.radius_secret}
                            />
                            <Input
                                label="Realm"
                                value={nasDevice.shortname}
                                readOnly
                                hint="Kode perusahaan. Pelanggan masuk sebagai nama@realm."
                            />
                        </CardContent>
                        <CardFooter className="justify-end gap-2">
                            <Button asChild variant="outline">
                                <Link href={showHref}>Batal</Link>
                            </Button>
                            <Button type="submit" loading={processing} disabled={processing}>
                                Simpan router
                            </Button>
                        </CardFooter>
                    </Card>
                </form>
            </div>
        </AdminLayout>
    );
}
