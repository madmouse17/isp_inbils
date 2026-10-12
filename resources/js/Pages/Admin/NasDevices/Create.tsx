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
    NativeSelect,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import type { RouterAssetOption } from '@/types/network-asset';

interface CreateProps extends Record<string, unknown> {
    routerAssets: RouterAssetOption[];
    /** Lower-cased company code; null when it cannot be used as a RADIUS realm. */
    realm: string | null;
}

const assetOptionLabel = (asset: RouterAssetOption) =>
    `${asset.code} - ${asset.name} (${asset.management_ip ?? 'no management IP'})${
        asset.location_name ? ` - ${asset.location_name}` : ''
    }`;

export default function Create({ routerAssets, realm }: CreateProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        network_asset_id: '',
        nasname: '',
        coa_port: '1700',
        radius_secret: '',
    });

    const selectAsset = (assetId: string) => {
        const asset = routerAssets.find((option) => String(option.id) === assetId);
        setData((current) => ({
            ...current,
            network_asset_id: assetId,
            nasname: asset?.management_ip ?? current.nasname,
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
        <AdminLayout title="Register NAS router">
            <div className="space-y-6">
                <PageHeader
                    title="Register NAS router"
                    subtitle="The router becomes a FreeRADIUS client with its own shared secret."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.nas-devices.index')}>Back</Link>
                        </Button>
                    }
                />

                {realm === null ? (
                    <Alert variant="warning">
                        <AlertDescription>
                            The company code cannot be used as a RADIUS realm (lower case letters,
                            digits and hyphens, up to 24 characters). Change the company code before
                            registering a router.
                        </AlertDescription>
                    </Alert>
                ) : routerAssets.length === 0 ? (
                    <Alert variant="info">
                        <AlertDescription className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <span>
                                No router asset is available. Add a router in Network Assets with a
                                management IP first.
                            </span>
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('admin.network-assets.index')}>
                                    Network assets
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
                                <NativeSelect
                                    label="Router asset"
                                    value={data.network_asset_id}
                                    onChange={(event) => selectAsset(event.target.value)}
                                    error={errors.network_asset_id}
                                    required
                                >
                                    <option value="">Select a router asset</option>
                                    {routerAssets.map((asset) => (
                                        <option key={asset.id} value={asset.id}>
                                            {assetOptionLabel(asset)}
                                        </option>
                                    ))}
                                </NativeSelect>
                                {realmError ? (
                                    <p
                                        role="alert"
                                        className="text-sm text-red-700 dark:text-red-400"
                                    >
                                        {realmError}
                                    </p>
                                ) : null}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Input
                                        label="NAS address"
                                        value={data.nasname}
                                        onChange={(event) => setData('nasname', event.target.value)}
                                        hint="The IPv4 address FreeRADIUS sees the router's requests come from."
                                        error={errors.nasname}
                                        inputMode="decimal"
                                        required
                                    />
                                    <Input
                                        label="CoA port"
                                        type="number"
                                        min={1}
                                        max={65535}
                                        value={data.coa_port}
                                        onChange={(event) =>
                                            setData('coa_port', event.target.value)
                                        }
                                        hint="MikroTik /radius incoming port"
                                        error={errors.coa_port}
                                        required
                                    />
                                </div>
                                <Input
                                    label="RADIUS secret"
                                    type="password"
                                    autoComplete="off"
                                    value={data.radius_secret}
                                    onChange={(event) =>
                                        setData('radius_secret', event.target.value)
                                    }
                                    hint="Same value as the secret on the router. Stored encrypted. Inbils never shows it again."
                                    error={errors.radius_secret}
                                    required
                                />
                                <Input
                                    label="Realm"
                                    value={realm}
                                    readOnly
                                    hint="The company code. Subscribers log in as name@realm."
                                />
                            </CardContent>
                            <CardFooter className="justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => router.get(route('admin.nas-devices.index'))}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" loading={processing} disabled={processing}>
                                    Register router
                                </Button>
                            </CardFooter>
                        </Card>
                    </form>
                )}
            </div>
        </AdminLayout>
    );
}
