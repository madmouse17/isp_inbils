import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { CircleDashed, Network, User } from 'lucide-react';
import {
    ConfirmRequestDialog,
    type ConfirmRequest,
} from '@/Components/composite/ConfirmRequestDialog';
import { Button, Card, CardContent, CardHeader, CardTitle, NativeSelect } from '@/Components/ui';
import type { InputOption, Splitter } from '@/types/ipam-odn';

interface SplitterCardProps {
    splitter: Splitter;
    /** Free upstream ports the input can be connected to. */
    inputOptions: InputOption[];
    can: { manage: boolean; assign: boolean };
}

export function SplitterCard({ splitter, inputOptions, can }: SplitterCardProps) {
    const [confirm, setConfirm] = useState<ConfirmRequest | null>(null);
    const current = splitter.input ? String(splitter.input.port_id) : '';
    const form = useForm({ input_port_id: current });
    const ratio = `1:${splitter.outputs}`;
    const inUse = splitter.ports.filter(
        (port) => port.subscription !== null || port.feeds_splitter_id !== null,
    ).length;

    const reconnect = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            input_port_id: values.input_port_id === '' ? null : Number(values.input_port_id),
        }));
        form.put(route('admin.odn.splitters.update', splitter.id), { preserveScroll: true });
    };

    return (
        <Card id={`splitter-${splitter.id}`}>
            <CardHeader className="sm:flex-row sm:items-start sm:justify-between">
                <div className="space-y-1.5">
                    <CardTitle>
                        Splitter {ratio}
                        {splitter.label ? ` - ${splitter.label}` : ''}
                    </CardTitle>
                    <p className="text-sm text-muted-foreground">
                        Input: {splitter.input ? splitter.input.label : 'not connected'} · {inUse}{' '}
                        of {splitter.outputs} outputs in use
                    </p>
                </div>
                {can.manage && (
                    <Button
                        type="button"
                        variant="danger"
                        className="self-start"
                        size="sm"
                        aria-label={`Delete splitter ${ratio}`}
                        onClick={() =>
                            setConfirm({
                                title: `Delete splitter ${ratio}?`,
                                description:
                                    'Its output ports are removed with it. Release every drop and disconnect downstream splitters first.',
                                confirmLabel: 'Delete splitter',
                                href: route('admin.odn.splitters.destroy', splitter.id),
                            })
                        }
                    >
                        Delete
                    </Button>
                )}
            </CardHeader>
            <CardContent className="space-y-4">
                {can.manage && (
                    <form
                        onSubmit={reconnect}
                        className="flex flex-col gap-2 sm:flex-row sm:items-end"
                    >
                        <div className="sm:max-w-md sm:flex-1">
                            <NativeSelect
                                id={`splitter-${splitter.id}-input`}
                                label="Upstream port"
                                value={form.data.input_port_id}
                                onChange={(event) =>
                                    form.setData('input_port_id', event.target.value)
                                }
                                error={form.errors.input_port_id}
                                options={[
                                    { value: '', label: 'Not connected' },
                                    ...(splitter.input
                                        ? [
                                              {
                                                  value: current,
                                                  label: `${splitter.input.label} (current)`,
                                              },
                                          ]
                                        : []),
                                    ...inputOptions.map((option) => ({
                                        value: String(option.port_id),
                                        label: option.label,
                                    })),
                                ]}
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="secondary"
                            loading={form.processing}
                            disabled={form.data.input_port_id === current}
                        >
                            Save connection
                        </Button>
                    </form>
                )}

                <ul className="grid grid-cols-2 gap-2 sm:grid-cols-4 2xl:grid-cols-8">
                    {splitter.ports.map((port) => {
                        const state = port.subscription
                            ? `customer ${port.subscription.code}`
                            : port.feeds_splitter_id !== null
                              ? `feeds splitter ${port.feeds_splitter_id}`
                              : 'free';

                        return (
                            <li key={port.id}>
                                <div
                                    role="group"
                                    tabIndex={0}
                                    aria-label={`Port ${port.number}, ${state}`}
                                    className={`flex h-full min-h-20 flex-col gap-1 rounded-md border p-2 text-sm focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden ${
                                        port.subscription
                                            ? 'bg-secondary'
                                            : port.feeds_splitter_id !== null
                                              ? 'bg-muted'
                                              : 'border-dashed'
                                    }`}
                                >
                                    <span className="font-medium">
                                        Port {port.number}
                                        {port.label ? ` · ${port.label}` : ''}
                                    </span>
                                    {port.subscription ? (
                                        <>
                                            <Link
                                                href={route(
                                                    'admin.subscriptions.show',
                                                    port.subscription.id,
                                                )}
                                                className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                                            >
                                                <User
                                                    className="size-3.5 shrink-0"
                                                    aria-hidden="true"
                                                />
                                                {port.subscription.code}
                                            </Link>
                                            <span
                                                className="truncate text-xs text-muted-foreground"
                                                title={port.subscription.customer_name ?? undefined}
                                            >
                                                {port.subscription.customer_name}
                                            </span>
                                            {can.assign && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    aria-label={`Release drop on port ${port.number}`}
                                                    onClick={() =>
                                                        setConfirm({
                                                            title: `Release ${port.subscription?.code} from port ${port.number}?`,
                                                            description:
                                                                'The port becomes free. The subscription itself is not changed.',
                                                            confirmLabel: 'Release drop',
                                                            href: route(
                                                                'admin.odn.drops.destroy',
                                                                port.id,
                                                            ),
                                                        })
                                                    }
                                                >
                                                    Release
                                                </Button>
                                            )}
                                        </>
                                    ) : port.feeds_splitter_id !== null ? (
                                        <span className="inline-flex items-center gap-1 text-muted-foreground">
                                            <Network
                                                className="size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            Feeds splitter {port.feeds_splitter_id}
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-muted-foreground">
                                            <CircleDashed
                                                className="size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            Free
                                        </span>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </CardContent>

            {confirm && (
                <ConfirmRequestDialog
                    key={confirm.href}
                    request={confirm}
                    onClose={() => setConfirm(null)}
                />
            )}
        </Card>
    );
}
