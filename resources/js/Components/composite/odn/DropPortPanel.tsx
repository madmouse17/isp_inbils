import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
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
    /** Free ports; the server delivers them only on a partial reload that asks for `dropPortOptions`. */
    options: DropPortOption[] | undefined;
}

export function DropPortPanel({ subscription, dropPort, canAssign, options }: DropPortPanelProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmRequest | null>(null);
    const editable = canAssign && !subscription.terminated;

    const openDialog = () => {
        setDialogOpen(true);

        // The ports on offer change as other drops are recorded, so every opening asks again.
        if (subscription.hasServingPop) {
            setLoading(true);
            router.reload({
                only: ['dropPortOptions'],
                replace: true,
                onFinish: () => setLoading(false),
            });
        }
    };

    return (
        <Card>
            <CardHeader className="sm:flex-row sm:items-start sm:justify-between">
                <CardTitle>Drop port</CardTitle>
                {editable && (
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" size="sm" onClick={openDialog}>
                            {dropPort ? 'Move drop port' : 'Set drop port'}
                        </Button>
                        {dropPort && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setConfirm({
                                        title: `Release port ${dropPort.number} on ${dropPort.odp_code}?`,
                                        description: `${subscription.code} keeps its service. The port becomes free.`,
                                        confirmLabel: 'Release drop',
                                        href: route('admin.odn.drops.destroy', dropPort.port_id),
                                    })
                                }
                            >
                                Release
                            </Button>
                        )}
                    </div>
                )}
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
                {dropPort ? (
                    <>
                        <p>
                            Port {dropPort.number} on{' '}
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
                    <p className="text-muted-foreground">No drop port recorded.</p>
                )}
            </CardContent>

            {dialogOpen && (
                <AssignDropDialog
                    onClose={() => setDialogOpen(false)}
                    subscription={subscription}
                    moving={dropPort !== null}
                    options={options}
                    loading={loading}
                    unavailable={
                        subscription.hasServingPop
                            ? undefined
                            : 'This subscription has no serving POP, so no ports can be offered.'
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
