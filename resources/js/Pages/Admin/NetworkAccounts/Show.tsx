import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertTitle,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    NativeSelect,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { CopyButton } from '@/Components/composite/CopyButton';
import {
    DesiredStateBadge,
    desiredStateExplanation,
    OnlineBadge,
    serviceTypeLabel,
    SyncStatusBadge,
} from '@/Components/composite/NetworkAccessBadges';
import { ToneBadge, type BadgeTone } from '@/Components/composite/ToneBadge';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/format';
import type {
    NasOption,
    NetworkAccount,
    NetworkAccountSubscription,
    NetworkDesiredState,
    NetworkServiceType,
} from '@/types/network-asset';

interface ShowProps extends Record<string, unknown> {
    subscription: NetworkAccountSubscription;
    account: NetworkAccount | null;
    desiredState: NetworkDesiredState;
    nasOptions: NasOption[];
    installEnabled: boolean;
    can: { manage: boolean; reveal: boolean };
    errors?: Record<string, string | undefined>;
}

const subscriptionStatusTones: Record<string, BadgeTone> = {
    pending: 'warning',
    active: 'success',
    suspended: 'warning',
    terminated: 'danger',
};

const serviceTypeOptions = (Object.keys(serviceTypeLabel) as NetworkServiceType[]).map((value) => ({
    value,
    label: serviceTypeLabel[value],
}));

/** Reads the one-time password out of the flash data of a visit; the shape is fixed by NetworkAccountController. */
function passwordFrom(flash: Record<string, unknown>): string | null {
    const sent = flash.network_account as { password?: string } | undefined;

    return sent?.password ?? null;
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 sm:grid-cols-[10rem_1fr]">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 wrap-break-word text-foreground">{children}</dd>
        </div>
    );
}

function routerOptions(nasOptions: NasOption[]) {
    return nasOptions.map((option) => ({
        value: String(option.id),
        label: option.suggested ? `${option.label} (suggested: same POP)` : option.label,
    }));
}

/** The suggested router (same POP) or the only router; otherwise the user chooses. */
function defaultRouterId(nasOptions: NasOption[]): string {
    const preselected = nasOptions.find((option) => option.suggested) ?? nasOptions[0];

    return preselected && (preselected.suggested || nasOptions.length === 1)
        ? String(preselected.id)
        : '';
}

function CreateAccountCard({
    subscriptionId,
    nasOptions,
    onPassword,
}: {
    subscriptionId: number;
    nasOptions: NasOption[];
    onPassword: (password: string) => void;
}) {
    const can = usePermission();
    const { data, setData, post, processing, errors } = useForm({
        nas_device_id: defaultRouterId(nasOptions),
        service_type: 'pppoe' as NetworkServiceType,
        username: '',
        password: '',
    });

    if (nasOptions.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Create network account</CardTitle>
                </CardHeader>
                <CardContent>
                    <Alert variant="info">
                        <AlertDescription className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <span>No NAS router is registered yet.</span>
                            {can('network_asset.nas.manage') && (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={route('admin.nas-devices.create')}>
                                        Register a NAS router
                                    </Link>
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                </CardContent>
            </Card>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.subscriptions.network-account.store', subscriptionId), {
            preserveScroll: true,
            onFlash: (flash) => {
                const password = passwordFrom(flash);
                if (password) onPassword(password);
            },
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Create network account</CardTitle>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <NativeSelect
                        label="NAS router"
                        value={data.nas_device_id}
                        onChange={(event) => setData('nas_device_id', event.target.value)}
                        options={[
                            ...(data.nas_device_id === ''
                                ? [{ value: '', label: 'Select a NAS router' }]
                                : []),
                            ...routerOptions(nasOptions),
                        ]}
                        error={errors.nas_device_id}
                        required
                    />
                    <NativeSelect
                        label="Service type"
                        value={data.service_type}
                        onChange={(event) =>
                            setData('service_type', event.target.value as NetworkServiceType)
                        }
                        options={serviceTypeOptions}
                        error={errors.service_type}
                        required
                    />
                    <Input
                        label="Username"
                        value={data.username}
                        onChange={(event) => setData('username', event.target.value)}
                        hint="3 to 64 letters, digits, dots, dashes, underscores or @."
                        error={errors.username}
                        required
                    />
                    <Input
                        label="Password"
                        type="password"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        hint="Leave blank to generate a password."
                        error={errors.password}
                    />
                    <Button
                        type="submit"
                        className="min-h-11"
                        loading={processing}
                        disabled={processing}
                    >
                        Create network account
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function EditAccountCard({
    subscription,
    account,
    nasOptions,
}: {
    subscription: NetworkAccountSubscription;
    account: NetworkAccount;
    nasOptions: NasOption[];
}) {
    const terminated = subscription.status === 'terminated';
    const { data, setData, put, processing, errors, reset } = useForm({
        nas_device_id: String(account.nas_device_id),
        service_type: account.service_type,
        username: account.username,
        password: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.subscriptions.network-account.update', subscription.id), {
            preserveScroll: true,
            onSuccess: () => reset('password'),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Edit network account</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {terminated && (
                    <Alert variant="warning">
                        <AlertDescription>
                            A terminated subscription cannot have a network account.
                        </AlertDescription>
                    </Alert>
                )}
                <form onSubmit={submit} className="space-y-4">
                    <fieldset disabled={terminated} className="space-y-4 min-w-0">
                        <NativeSelect
                            label="NAS router"
                            value={data.nas_device_id}
                            onChange={(event) => setData('nas_device_id', event.target.value)}
                            options={routerOptions(nasOptions)}
                            error={errors.nas_device_id}
                            required
                        />
                        <NativeSelect
                            label="Service type"
                            value={data.service_type}
                            onChange={(event) =>
                                setData('service_type', event.target.value as NetworkServiceType)
                            }
                            options={serviceTypeOptions}
                            error={errors.service_type}
                            required
                        />
                        <Input
                            label="Username"
                            value={data.username}
                            onChange={(event) => setData('username', event.target.value)}
                            hint="3 to 64 letters, digits, dots, dashes, underscores or @."
                            error={errors.username}
                            required
                        />
                        <Input
                            label="Password"
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            hint="Leave blank to keep the current password."
                            error={errors.password}
                        />
                        <Button
                            type="submit"
                            className="min-h-11"
                            loading={processing}
                            disabled={processing || terminated}
                        >
                            Save changes
                        </Button>
                    </fieldset>
                </form>
            </CardContent>
        </Card>
    );
}

export default function Show({
    subscription,
    account,
    desiredState,
    nasOptions,
    installEnabled,
    can,
    errors = {},
}: ShowProps) {
    // The password comes as flash data, in no prop, so the page keeps it only until it is dismissed or
    // replaced. Nothing else ever receives it.
    const [password, setPassword] = useState<string | null>(null);
    const [revealing, setRevealing] = useState(false);
    const [confirmingReveal, setConfirmingReveal] = useState(false);
    const [resyncing, setResyncing] = useState(false);

    const reveal = () => {
        setRevealing(true);
        router.post(
            route('admin.subscriptions.network-account.reveal', subscription.id),
            {},
            {
                preserveScroll: true,
                onFlash: (flash) => setPassword(passwordFrom(flash) ?? null),
                onFinish: () => {
                    setRevealing(false);
                    setConfirmingReveal(false);
                },
            },
        );
    };

    const resync = () => {
        setResyncing(true);
        router.post(
            route('admin.subscriptions.network-account.resync', subscription.id),
            {},
            { preserveScroll: true, onFinish: () => setResyncing(false) },
        );
    };

    const speedProfile = subscription.speed_profile;

    return (
        <AdminLayout title={`Network access ${subscription.code}`}>
            <div className="space-y-6">
                <PageHeader
                    title="Network access"
                    subtitle={`Subscription ${subscription.code}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.subscriptions.show', subscription.id)}>
                                    Back
                                </Link>
                            </Button>
                            {account && can.manage ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    loading={resyncing}
                                    onClick={resync}
                                >
                                    Resync
                                </Button>
                            ) : null}
                            {account && can.reveal ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setConfirmingReveal(true)}
                                >
                                    Reveal password
                                </Button>
                            ) : null}
                        </>
                    }
                />

                {!installEnabled && (
                    <Alert variant="warning">
                        <AlertDescription>
                            Provisioning is switched off for this installation. Changes are saved
                            but not sent to routers.
                        </AlertDescription>
                    </Alert>
                )}

                {password !== null && (
                    <Alert variant="warning">
                        <AlertTitle>Password shown once</AlertTitle>
                        <AlertDescription className="space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <code className="rounded-md bg-muted px-2 py-1 font-mono text-base break-all text-foreground select-all">
                                    {password}
                                </code>
                                <CopyButton value={password} label="Copy password" />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setPassword(null)}
                                >
                                    Dismiss
                                </Button>
                            </div>
                            <p>
                                Copy it now. It is not kept after you dismiss this message or leave
                                the page.
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {errors.subscription && (
                    <Alert variant="danger">
                        <AlertDescription className="text-red-700 dark:text-red-400">
                            {errors.subscription}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Subscription</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-3 text-sm">
                                <Row label="Subscription">{subscription.code}</Row>
                                <Row label="Status">
                                    <ToneBadge
                                        tone={
                                            subscriptionStatusTones[subscription.status] ??
                                            'secondary'
                                        }
                                    >
                                        {subscription.status}
                                    </ToneBadge>
                                </Row>
                                <Row label="Customer">
                                    {subscription.customer ? (
                                        <Link
                                            href={route(
                                                'admin.customers.show',
                                                subscription.customer.id,
                                            )}
                                            className="font-medium text-brand-600 hover:underline dark:text-brand-400"
                                        >
                                            {subscription.customer.name}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </Row>
                                <Row label="Speed profile">
                                    {speedProfile ? (
                                        <>
                                            {speedProfile.name}
                                            {speedProfile.radius_profile_name
                                                ? `, router profile ${speedProfile.radius_profile_name}`
                                                : ''}
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </Row>
                                <Row label="Serving POP">
                                    {subscription.serving_pop?.name ?? '—'}
                                </Row>
                                <Row label="Desired state">
                                    <DesiredStateBadge state={desiredState} />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {desiredStateExplanation[desiredState]}
                                    </p>
                                </Row>
                            </dl>
                            {!speedProfile?.radius_profile_name && (
                                <Alert variant="warning" className="mt-4">
                                    <AlertDescription>
                                        The speed profile of this package has no router profile
                                        name. Set one on the speed profile before creating a network
                                        account.
                                    </AlertDescription>
                                </Alert>
                            )}
                        </CardContent>
                    </Card>

                    {account && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Network account</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-3 text-sm">
                                    <Row label="NAS router">{account.nas_label}</Row>
                                    <Row label="Service type">
                                        {serviceTypeLabel[account.service_type]}
                                    </Row>
                                    <Row label="Username">
                                        <span className="font-mono break-all">
                                            {account.username}
                                        </span>
                                    </Row>
                                    <Row label="Sync status">
                                        <SyncStatusBadge status={account.sync_status} />
                                        {account.last_error && (
                                            <p
                                                className={
                                                    account.sync_status === 'failed'
                                                        ? 'mt-1 text-xs text-red-700 dark:text-red-400'
                                                        : 'mt-1 text-xs text-muted-foreground'
                                                }
                                            >
                                                {account.last_error}
                                            </p>
                                        )}
                                    </Row>
                                    <Row label="Provisioned profile">
                                        {account.provisioned_profile
                                            ? `${account.provisioned_profile} since ${formatDateTime(account.provisioned_at)}`
                                            : 'Not on the router yet'}
                                    </Row>
                                    <Row label="Online">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <OnlineBadge account={account} />
                                            {account.current_address && (
                                                <span className="font-mono text-xs break-all">
                                                    {account.current_address}
                                                </span>
                                            )}
                                        </div>
                                        {account.last_online_at && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Last seen online{' '}
                                                {formatDateTime(account.last_online_at)}
                                            </p>
                                        )}
                                    </Row>
                                </dl>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {account && can.manage && (
                    <EditAccountCard
                        key={account.id}
                        subscription={subscription}
                        account={account}
                        nasOptions={nasOptions}
                    />
                )}

                {!account && can.manage && (
                    <CreateAccountCard
                        subscriptionId={subscription.id}
                        nasOptions={nasOptions}
                        onPassword={setPassword}
                    />
                )}

                {!account && !can.manage && (
                    <p className="text-sm text-muted-foreground">
                        This subscription has no network account.
                    </p>
                )}
            </div>

            <AlertDialog
                open={confirmingReveal}
                onOpenChange={(open) => {
                    if (!revealing) setConfirmingReveal(open);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Reveal password?</AlertDialogTitle>
                        <AlertDialogDescription>
                            The password is shown once on this page and the reveal is recorded in
                            the audit log.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={revealing}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={revealing}
                            onClick={(event) => {
                                event.preventDefault();
                                reveal();
                            }}
                        >
                            Reveal
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AdminLayout>
    );
}
