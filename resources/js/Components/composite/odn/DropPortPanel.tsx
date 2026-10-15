import { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    ConfirmRequestDialog,
    type ConfirmRequest,
} from '@/Components/composite/ConfirmRequestDialog';
import { AssignDropDialog } from '@/Components/composite/odn/AssignDropDialog';
import { Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import type { DropPort, DropPortOption } from '@/types/ipam-odn';

interface DropPortPanelProps {
    subscription: { id: number; code: string; terminated: boolean; hasServingPop: boolean };
    dropPort: DropPort | null;
    canAssign: boolean;
    /** The `dropPortOptions` prop: free ports that match the typed text; the server sends it only on a partial reload that asks for it. */
    options: DropPortOption[] | undefined;
}

export function DropPortPanel({ subscription, dropPort, canAssign, options }: DropPortPanelProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmRequest | null>(null);
    const editable = canAssign && !subscription.terminated;

    return (
        <Card>
            <CardHeader className="sm:flex-row sm:items-start sm:justify-between">
                <CardTitle>Port drop</CardTitle>
                {editable && (
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" size="sm" onClick={() => setDialogOpen(true)}>
                            {dropPort ? 'Pindahkan port drop' : 'Atur port drop'}
                        </Button>
                        {dropPort && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setConfirm({
                                        title: `Lepas port ${dropPort.number} di ${dropPort.odp_code}?`,
                                        description: `${subscription.code} tetap berlangganan. Port menjadi kosong.`,
                                        confirmLabel: 'Lepas port drop',
                                        href: route('admin.odn.drops.destroy', dropPort.port_id),
                                    })
                                }
                            >
                                Lepas
                            </Button>
                        )}
                    </div>
                )}
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
                {dropPort ? (
                    <>
                        <p>
                            Port {dropPort.number} di{' '}
                            <Link
                                href={route('admin.odn.assets.show', dropPort.odp_asset_id)}
                                className="font-medium text-primary hover:underline"
                            >
                                {dropPort.odp_code}
                            </Link>{' '}
                            ({dropPort.odp_name})
                        </p>
                        {dropPort.location_path && (
                            <p className="text-muted-foreground">{dropPort.location_path}</p>
                        )}
                    </>
                ) : (
                    <p className="text-muted-foreground">Belum ada port drop tercatat.</p>
                )}
            </CardContent>

            {dialogOpen && (
                <AssignDropDialog
                    onClose={() => setDialogOpen(false)}
                    subscription={subscription}
                    moving={dropPort !== null}
                    options={options}
                    unavailable={
                        subscription.hasServingPop
                            ? undefined
                            : 'Langganan ini belum punya POP layanan, jadi belum ada port yang bisa ditawarkan.'
                    }
                />
            )}
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
