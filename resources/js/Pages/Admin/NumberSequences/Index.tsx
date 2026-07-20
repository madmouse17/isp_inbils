import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Badge, Card, CardContent, Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

interface SeqRow {
    id: number;
    entity_type: string;
    prefix: string;
    next_number: number;
    padding: number;
    year_suffix: boolean;
}

interface IndexProps extends Record<string, unknown> {
    sequences: { data: SeqRow[]; current_page: number; last_page: number };
}

export default function Index({ sequences }: IndexProps) {
    return (
        <AdminLayout title="Number Sequences">
            <div className="space-y-6">
                <PageHeader
                    title="Number Sequences"
                    subtitle="Centralized code generation for entities."
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Entity</TH>
                                    <TH>Prefix</TH>
                                    <TH>Next #</TH>
                                    <TH>Padding</TH>
                                    <TH>Year</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {sequences.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={5}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    sequences.data.map((s) => (
                                        <TR key={s.id}>
                                            <TD>
                                                <Badge variant="neutral">{s.entity_type}</Badge>
                                            </TD>
                                            <TD className="font-mono">{s.prefix}</TD>
                                            <TD>{s.next_number}</TD>
                                            <TD>{s.padding}</TD>
                                            <TD>{s.year_suffix ? 'Yes' : 'No'}</TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination
                            currentPage={sequences.current_page}
                            lastPage={sequences.last_page}
                            onPageChange={(page) => router.get(route('admin.number-sequences.index'), { page })}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
