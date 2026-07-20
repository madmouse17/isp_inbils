import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface PkgData {
    id: number;
    code: string;
    name: string;
    price_mrc: string;
    price_otc: string;
    contract_min_months: number | null;
    description: string | null;
    is_active: boolean;
    bandwidth_profile: { name: string } | null;
    speed_profile: { name: string } | null;
    sla_tier: { name: string } | null;
}

interface ShowProps extends Record<string, unknown> {
    servicePackage: { data: PkgData };
}

export default function Show({ servicePackage }: ShowProps) {
    const p = servicePackage.data;

    return (
        <AdminLayout title={p.name}>
            <div className="space-y-6">
                <PageHeader
                    title={p.name}
                    subtitle={p.code}
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    router.get(route('admin.service-packages.edit', p.id))
                                }
                            >
                                Edit
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => router.get(route('admin.service-packages.index'))}
                            >
                                Back
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Package Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                            <p>
                                <span className="text-muted-foreground">Bandwidth: </span>
                                {p.bandwidth_profile?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Speed: </span>
                                {p.speed_profile?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">SLA: </span>
                                {p.sla_tier?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Contract Min: </span>
                                {p.contract_min_months ? `${p.contract_min_months} months` : '-'}
                            </p>
                            {p.description && (
                                <p className="md:col-span-2">
                                    <span className="text-muted-foreground">Description: </span>
                                    {p.description}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Pricing</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">MRC: </span>
                                {p.price_mrc}
                            </p>
                            <p>
                                <span className="text-muted-foreground">OTC: </span>
                                {p.price_otc}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Status: </span>
                                <Badge variant={p.is_active ? 'success' : 'danger'}>
                                    {p.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
