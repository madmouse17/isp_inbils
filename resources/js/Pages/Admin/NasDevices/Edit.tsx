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
    NativeSelect,
    Switch,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { NAS_API_PROTOCOLS, portAfterProtocolChange } from '@/lib/nasApiProtocol';
import type { NasApiProtocol, NasDevice, RouterAssetOption } from '@/types/network-asset';

interface EditProps extends Record<string, unknown> {
    /** Only the router asset this NAS router is registered on: it cannot change. */
    routerAssets: RouterAssetOption[];
    nasDevice: NasDevice;
}

export default function Edit({ routerAssets, nasDevice }: EditProps) {
    const { data, setData, put, processing, errors, reset } = useForm({
        api_protocol: nasDevice.api_protocol,
        api_port: String(nasDevice.api_port),
        api_username: nasDevice.api_username,
        api_password: '',
        isolir_profile: nasDevice.isolir_profile,
        provisioning_enabled: nasDevice.provisioning_enabled,
    });

    const asset = routerAssets[0];
    const assetLabel = asset
        ? `${asset.code} - ${asset.name} (${asset.management_ip ?? 'no management IP'})`
        : (nasDevice.network_asset?.code ?? `Router #${nasDevice.id}`);
    const showHref = route('admin.nas-devices.show', nasDevice.id);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.nas-devices.update', nasDevice.id), {
            onSuccess: () => reset('api_password'),
        });
    };

    return (
        <AdminLayout title="Edit NAS router">
            <div className="space-y-6">
                <PageHeader
                    title="Edit NAS router"
                    subtitle="Inbils reaches the router over HTTPS (RouterOS REST API, RouterOS 7.1 or later)."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={showHref}>Back</Link>
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
                                <dt className="font-medium text-foreground">Router asset</dt>
                                <dd className="text-muted-foreground">{assetLabel}</dd>
                            </dl>
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
                                    onChange={(event) => setData('api_port', event.target.value)}
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
                                placeholder={nasDevice.has_api_password ? 'Stored' : undefined}
                                value={data.api_password}
                                onChange={(event) => setData('api_password', event.target.value)}
                                hint={
                                    nasDevice.has_api_password
                                        ? 'A password is stored. Leave blank to keep it.'
                                        : 'Stored encrypted. Inbils never shows it again.'
                                }
                                error={errors.api_password}
                            />
                            <Input
                                label="Isolir profile"
                                value={data.isolir_profile}
                                onChange={(event) => setData('isolir_profile', event.target.value)}
                                hint="Profile on the router that isolated subscribers are moved to (PPP profile and hotspot user profile)."
                                error={errors.isolir_profile}
                                required
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
                                    Writes change the router: Inbils creates, updates, isolates and
                                    removes PPPoE secrets and hotspot users on it. Keep this off
                                    until a check shows the router is reachable.
                                </p>
                            </div>
                        </CardContent>
                        <CardFooter className="justify-end gap-2">
                            <Button asChild variant="outline">
                                <Link href={showHref}>Cancel</Link>
                            </Button>
                            <Button type="submit" loading={processing} disabled={processing}>
                                Save router
                            </Button>
                        </CardFooter>
                    </Card>
                </form>
            </div>
        </AdminLayout>
    );
}
