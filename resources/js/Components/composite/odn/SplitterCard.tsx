import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { CircleDashed, Network, User } from 'lucide-react';
import {
    ConfirmRequestDialog,
    type ConfirmRequest,
} from '@/Components/composite/ConfirmRequestDialog';
import { RemoteSearchSelect } from '@/Components/composite/RemoteSearchSelect';
import { Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import type { PortOption, Splitter } from '@/types/ipam-odn';

interface SplitterCardProps {
    splitter: Splitter;
    /** The `inputPortOptions` prop: free upstream ports that match the typed text. `undefined` until a search has run. */
    inputPortOptions: PortOption[] | undefined;
    can: { manage: boolean; assign: boolean };
}

export function SplitterCard({ splitter, inputPortOptions, can }: SplitterCardProps) {
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
                        Masukan: {splitter.input ? splitter.input.label : 'belum terhubung'} ·{' '}
                        {inUse} dari {splitter.outputs} keluaran terpakai
                    </p>
                </div>
                {can.manage && (
                    <Button
                        type="button"
                        variant="outline"
                        className="self-start text-destructive-text"
                        size="sm"
                        aria-label={`Hapus splitter ${ratio}`}
                        onClick={() =>
                            setConfirm({
                                title: `Hapus splitter ${ratio}?`,
                                description:
                                    'Port keluarannya ikut terhapus. Lepas semua port drop dan putuskan splitter hilir lebih dulu.',
                                confirmLabel: 'Hapus splitter',
                                href: route('admin.odn.splitters.destroy', splitter.id),
                            })
                        }
                    >
                        Hapus
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
                            <RemoteSearchSelect
                                field="inputPort"
                                id={`splitter-${splitter.id}-input`}
                                label="Port masukan"
                                value={form.data.input_port_id}
                                onChange={(value) => form.setData('input_port_id', value)}
                                options={inputPortOptions}
                                selected={
                                    splitter.input
                                        ? {
                                              value: splitter.input.port_id,
                                              label: splitter.input.label,
                                          }
                                        : null
                                }
                                placeholder="Belum terhubung"
                                emptyText="Tidak ada port kosong yang cocok."
                                error={form.errors.input_port_id}
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="secondary"
                            loading={form.processing}
                            disabled={form.data.input_port_id === current}
                        >
                            Simpan sambungan
                        </Button>
                    </form>
                )}

                <ul className="grid grid-cols-2 gap-2 sm:grid-cols-4 2xl:grid-cols-8">
                    {splitter.ports.map((port) => {
                        const state = port.subscription
                            ? `pelanggan ${port.subscription.code}`
                            : port.feeds_splitter_id !== null
                              ? `menyuplai splitter ${port.feeds_splitter_id}`
                              : 'kosong';

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
                                                    aria-label={`Lepas port drop di port ${port.number}`}
                                                    onClick={() =>
                                                        setConfirm({
                                                            title: `Lepas ${port.subscription?.code} dari port ${port.number}?`,
                                                            description:
                                                                'Port menjadi kosong. Langganannya sendiri tidak berubah.',
                                                            confirmLabel: 'Lepas port drop',
                                                            href: route(
                                                                'admin.odn.drops.destroy',
                                                                port.id,
                                                            ),
                                                        })
                                                    }
                                                >
                                                    Lepas
                                                </Button>
                                            )}
                                        </>
                                    ) : port.feeds_splitter_id !== null ? (
                                        <span className="inline-flex items-center gap-1 text-muted-foreground">
                                            <Network
                                                className="size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            Menyuplai splitter {port.feeds_splitter_id}
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-muted-foreground">
                                            <CircleDashed
                                                className="size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            Kosong
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
