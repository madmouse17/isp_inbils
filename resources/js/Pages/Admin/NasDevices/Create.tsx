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
    Switch,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { NAS_API_PROTOCOLS, portAfterProtocolChange } from '@/lib/nasApiProtocol';
import type { NasApiProtocol, RouterAssetOption } from '@/types/network-asset';

interface CreateProps extends Record<string, unknown> {
    routerAssets: RouterAssetOption[];
}

const assetOptionLabel = (asset: RouterAssetOption) =>
    `${asset.code} - ${asset.name} (${asset.management_ip ?? 'no management IP'})${
        asset.location_name ? ` - ${asset.location_name}` : ''
    }`;

export default function Create({ routerAssets }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        network_asset_id: '',
        api_protocol: 'rest' as NasApiProtocol,
        api_port: '443',
        api_username: '',
        api_password: '',
        isolir_profile: 'isolir',
        provisioning_enabled: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.nas-devices.store'));
    };

    return (
        <AdminLayout title="Register NAS router">
            <div className="space-y-6">
                <PageHeader
                    title="Register NAS router"
                    subtitle="Inbils reaches the router over its API: REST over HTTPS (RouterOS 7.1 or later) or the binary API (RouterOS 6 or 7)."
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

                {routerAssets.length === 0 ? (
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
                                    onChange={(event) =>
                                        setData('network_asset_id', event.target.value)
                                    }
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
                                <NativeSelect
                                    label="API protocol"
                                    value={data.api_protocol}
                                    onChange={(event) => {
                                        const protocol = event.target.value as NasApiProtocol;
                                        setData((current) => ({
                                            ...current,
                                            api_protocol: protocol,
                                            api_port: portAfterProtocolChange(
                                                current.api_port,
                                                protocol,
                                            ),
                                        }));
                                    }}
                                    hint={
                                        NAS_API_PROTOCOLS.find(
                                            (option) => option.value === data.api_protocol,
                                        )?.hint
                                    }
                                    error={errors.api_protocol}
                                >
                                    {NAS_API_PROTOCOLS.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Input
                                        label="API port"
                                        type="number"
                                        min={1}
                                        max={65535}
                                        value={data.api_port}
                                        onChange={(event) =>
                                            setData('api_port', event.target.value)
                                        }
                                        error={errors.api_port}
                                        required
                                    />
                                    <Input
                                        label="API username"
                                        value={data.api_username}
                                        onChange={(event) =>
                                            setData('api_username', event.target.value)
                                        }
                                        error={errors.api_username}
                                        required
                                    />
                                </div>
                                <Input
                                    label="API password"
                                    type="password"
                                    autoComplete="new-password"
                                    value={data.api_password}
                                    onChange={(event) =>
                                        setData('api_password', event.target.value)
                                    }
                                    hint="Stored encrypted. Inbils never shows it again."
                                    error={errors.api_password}
                                    required
                                />
                                <Input
                                    label="Isolir profile"
                                    value={data.isolir_profile}
                                    onChange={(event) =>
                                        setData('isolir_profile', event.target.value)
                                    }
                                    hint="Profile on the router that isolated subscribers are moved to (PPP profile and hotspot user profile)."
                                    error={errors.isolir_profile}
                                />
                                <div className="space-y-2">
                                    <Switch
                                        label="Allow Inbils to write to this router"
                                        checked={data.provisioning_enabled}
                                        onCheckedChange={(checked) =>
                                            setData('provisioning_enabled', checked)
                                        }
                                    />
                                    <p className="text-sm text-amber-700 dark:text-amber-400">
                                        Writes change the router: Inbils creates, updates, isolates
                                        and removes PPPoE secrets and hotspot users on it. Keep this
                                        off until a check shows the router is reachable.
                                    </p>
                                </div>
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
