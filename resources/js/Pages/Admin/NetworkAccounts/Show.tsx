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
import { formatDateTime } from '@/lib/format';
import type {
    NetworkAccount,
    NetworkAccountSubscription,
    NetworkDesiredState,
    RadiusSession,
    NetworkServiceType,
} from '@/types/network-asset';

interface ShowProps extends Record<string, unknown> {
    subscription: NetworkAccountSubscription;
    /** Lower-cased company code; null when it cannot be used as a RADIUS realm. */
    realm: string | null;
    account: NetworkAccount | null;
    /** Null while RADIUS is off or unreachable, or when the subscriber is offline. */
    session: RadiusSession | null;
    usage: { month: string; input_octets: number; output_octets: number } | null;
    desiredState: NetworkDesiredState;
    isolatedBecause: ('suspended' | 'blocked')[];
    radiusEnabled: boolean;
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

/** Reads the one-time secret out of the flash data of a visit; the shape is fixed by NetworkAccountController. */
function secretFrom(flash: Record<string, unknown>): string | null {
    const sent = flash.network_account as { secret?: string } | undefined;

    return sent?.secret ?? null;
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 sm:grid-cols-[10rem_1fr]">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 wrap-break-word text-foreground">{children}</dd>
        </div>
    );
}

const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

function formatBytes(bytes: number): string {
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < BYTE_UNITS.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${unit === 0 ? value : value.toFixed(2)} ${BYTE_UNITS[unit]}`;
}

/** The username is `local@realm`; staff edit the local part, the realm is fixed by the company. */
function localPart(username: string, realm: string | null): string {
    const suffix = realm ? `@${realm}` : '';

    return suffix && username.endsWith(suffix) ? username.slice(0, -suffix.length) : username;
}

function UsernameField({
    value,
    realm,
    error,
    onChange,
}: {
    value: string;
    realm: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    const describedBy = error ? 'account-username-error' : 'account-username-hint';

    return (
        <div className="space-y-1.5">
            <label htmlFor="account-username" className="text-sm leading-none font-medium">
                Username
                <span aria-hidden="true" className="text-destructive">
                    {' '}
                    *
                </span>
            </label>
            <div className="flex items-center gap-2">
                <Input
                    id="account-username"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    autoComplete="off"
                    aria-describedby={describedBy}
                    aria-invalid={error ? true : undefined}
                    required
                />
                <span className="font-mono text-sm break-all text-muted-foreground">@{realm}</span>
            </div>
            {error ? (
                <p id="account-username-error" className="text-sm text-red-700 dark:text-red-400">
                    {error}
                </p>
            ) : (
                <p id="account-username-hint" className="text-sm text-muted-foreground">
                    3 to 40 lower case letters, digits, dots, dashes or underscores.
                </p>
            )}
        </div>
    );
}

function CreateAccountCard({
    subscriptionId,
    realm,
    onSecret,
}: {
    subscriptionId: number;
    realm: string | null;
    onSecret: (secret: string) => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        service_type: 'pppoe' as NetworkServiceType,
        username: '',
        password: '',
    });

    if (realm === null) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Create network account</CardTitle>
                </CardHeader>
                <CardContent>
                    <Alert variant="warning">
                        <AlertDescription>
                            The company code cannot be used as a RADIUS realm (lower case letters,
                            digits and hyphens, up to 24 characters). Change the company code before
                            creating network accounts.
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
                const secret = secretFrom(flash);
                if (secret) onSecret(secret);
            },
            onFinish: () => setData('password', ''),
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
                        label="Service type"
                        value={data.service_type}
                        onChange={(event) =>
                            setData('service_type', event.target.value as NetworkServiceType)
                        }
                        options={serviceTypeOptions}
                        error={errors.service_type}
                        required
                    />
                    <UsernameField
                        value={data.username}
                        realm={realm}
                        error={errors.username}
                        onChange={(value) => setData('username', value)}
                    />
                    <Input
                        label="Secret"
                        type="password"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        hint="6 to 64 characters without spaces. Leave blank to generate one."
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
    realm,
}: {
    subscription: NetworkAccountSubscription;
    account: NetworkAccount;
    realm: string;
}) {
    const terminated = subscription.status === 'terminated';
    const { data, setData, put, processing, errors, reset } = useForm({
        service_type: account.service_type,
        username: localPart(account.username, realm),
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
                            label="Service type"
                            value={data.service_type}
                            onChange={(event) =>
                                setData('service_type', event.target.value as NetworkServiceType)
                            }
                            options={serviceTypeOptions}
                            error={errors.service_type}
                            required
                        />
                        <UsernameField
                            value={data.username}
                            realm={realm}
                            error={errors.username}
                            onChange={(value) => setData('username', value)}
                        />
                        <Input
                            label="Secret"
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            hint="Leave blank to keep the current secret."
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
    realm,
    session,
    usage,
    isolatedBecause,
    radiusEnabled,
    can,
    errors = {},
}: ShowProps) {
    // The secret comes as flash data, in no prop, so the page keeps it only until it is dismissed or
    // replaced. Nothing else ever receives it.
    const [secret, setSecret] = useState<string | null>(null);
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
                onFlash: (flash) => setSecret(secretFrom(flash) ?? null),
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
                                    Reveal secret
                                </Button>
                            ) : null}
                        </>
                    }
                />

                {!radiusEnabled && (
                    <Alert variant="warning">
                        <AlertDescription>
                            RADIUS is switched off for this installation. Changes are saved but not
                            sent to FreeRADIUS, and online status and usage are not available.
                        </AlertDescription>
                    </Alert>
                )}

                {secret !== null && (
                    <Alert variant="warning">
                        <AlertTitle>Secret shown once</AlertTitle>
                        <AlertDescription className="space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <code className="rounded-md bg-muted px-2 py-1 font-mono text-base break-all text-foreground select-all">
                                    {secret}
                                </code>
                                <CopyButton value={secret} label="Copy secret" />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setSecret(null)}
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

                {(errors.subscription || errors.realm) && (
                    <Alert variant="danger">
                        <AlertDescription className="text-red-700 dark:text-red-400">
                            {errors.subscription ?? errors.realm}
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
                                                ? `, RADIUS group ${speedProfile.radius_profile_name}`
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
                                    {isolatedBecause.length > 0 ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Isolated because{' '}
                                            {isolatedBecause
                                                .map((reason) =>
                                                    reason === 'suspended'
                                                        ? 'Suspended'
                                                        : 'Blocked',
                                                )
                                                .join(' and ')}
                                            .
                                        </p>
                                    ) : null}
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {desiredStateExplanation[desiredState]}
                                    </p>
                                </Row>
                            </dl>
                            {!speedProfile?.radius_profile_name && (
                                <Alert variant="warning" className="mt-4">
                                    <AlertDescription>
                                        The speed profile of this package has no RADIUS profile
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
                                        {account.disconnect_pending && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                A disconnect of the open session is still owed.
                                            </p>
                                        )}
                                    </Row>
                                    <Row label="RADIUS group">
                                        {account.provisioned_group
                                            ? `${account.provisioned_group} since ${formatDateTime(account.provisioned_at)}`
                                            : 'Not in RADIUS yet'}
                                    </Row>
                                    <Row label="Online">
                                        <OnlineBadge online={session !== null} />
                                    </Row>
                                    {session && (
                                        <>
                                            <Row label="Address">
                                                <span className="font-mono break-all">
                                                    {session.framed_ip ?? '—'}
                                                </span>
                                            </Row>
                                            <Row label="NAS">
                                                {session.nas_label ?? session.nas_ip}
                                            </Row>
                                            <Row label="Session start">
                                                {formatDateTime(session.started_at)}
                                            </Row>
                                        </>
                                    )}
                                    <Row label="This month">
                                        {usage
                                            ? `Upload ${formatBytes(usage.input_octets)}, download ${formatBytes(usage.output_octets)}`
                                            : radiusEnabled
                                              ? 'Usage is not available right now. RADIUS did not answer.'
                                              : '—'}
                                    </Row>
                                </dl>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {account && can.manage && realm !== null && (
                    <EditAccountCard
                        key={account.id}
                        subscription={subscription}
                        account={account}
                        realm={realm}
                    />
                )}

                {!account && can.manage && (
                    <CreateAccountCard
                        subscriptionId={subscription.id}
                        realm={realm}
                        onSecret={setSecret}
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
                        <AlertDialogTitle>Reveal secret?</AlertDialogTitle>
                        <AlertDialogDescription>
                            The secret is shown once on this page and the reveal is recorded in the
                            audit log.
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
