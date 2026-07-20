import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import { CustomerRelatedTables, PageHeader, StatusBadge } from '@/Components/composite';
import type { Customer } from '@/types/models';

interface ShowProps extends Record<string, unknown> {
    customer: { data: Customer };
}

export default function Show({ customer }: ShowProps) {
    const c = customer.data;

    return (
        <AdminLayout title={c.name}>
            <div className="space-y-6">
                <PageHeader
                    title={c.name}
                    subtitle={`${c.code} - ${c.type}`}
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.get(route('admin.customers.edit', c.id))}
                            >
                                Edit
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => router.get(route('admin.customers.index'))}
                            >
                                Back
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
                    customerId={c.id}
                    addresses={c.addresses ?? []}
                    contacts={c.contacts ?? []}
                    subscriptions={c.subscriptions ?? []}
                />
            </div>
        </AdminLayout>
    );
}
