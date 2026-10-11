import { Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import {
    CustomerAcquirerValue,
    CustomerRelatedTables,
    PageHeader,
    StatusBadge,
    type CustomerHistory,
    type CustomerHistoryAccess,
} from '@/Components/composite';
import type { Customer } from '@/types/models';

interface ShowProps extends Record<string, unknown> {
    customer: { data: Customer };
    history: CustomerHistory;
    historyAccess: CustomerHistoryAccess;
    can: { viewAcquirer: boolean };
}

export default function Show({ customer, history, historyAccess, can }: ShowProps) {
    const c = customer.data;

    return (
        <AdminLayout title={c.name ?? ''}>
            <div className="space-y-6">
                <PageHeader
                    title={c.name ?? ''}
                    subtitle={`${c.code ?? ''} - ${c.type ?? ''}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.customers.index')}>Back</Link>
                            </Button>
                            <Button
                                type="button"
                                variant="warning"
                                onClick={() => router.get(route('admin.customers.edit', c.id))}
                            >
                                Edit
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Email: </span>
                                {c.email ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Phone: </span>
                                {c.phone ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Tax ID: </span>
                                {c.tax_id ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Contact Person: </span>
                                {c.contact_person ?? '-'}
                            </p>
                            {can.viewAcquirer ? (
                                <div>
                                    <span className="text-muted-foreground">Acquired by: </span>
                                    <CustomerAcquirerValue acquirer={c.acquirer} />
                                </div>
                            ) : null}
                            {c.notes && (
                                <p>
                                    <span className="text-muted-foreground">Notes: </span>
                                    {c.notes}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Type: </span>
                                <Badge variant={c.type === 'Company' ? 'brand' : 'neutral'}>
                                    {c.type}
                                </Badge>
                            </p>
                            <p>
                                <span className="text-muted-foreground">Status: </span>
                                <StatusBadge variant={c.is_active ? 'success' : 'danger'}>
                                    {c.is_active ? 'Active' : 'Inactive'}
                                </StatusBadge>
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <CustomerRelatedTables
                    addresses={c.addresses ?? []}
                    contacts={c.contacts ?? []}
                    subscriptions={c.subscriptions ?? []}
                    history={history}
                    historyAccess={historyAccess}
                />
            </div>
        </AdminLayout>
    );
}
