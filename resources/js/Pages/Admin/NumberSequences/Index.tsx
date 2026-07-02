import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Input, Switch, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { useForm } from '@inertiajs/react';

interface SeqRow {
    id: number; entity_type: string; prefix: string; next_number: number; padding: number; year_suffix: boolean;
}

interface IndexProps extends Record<string, unknown> {
    sequences: { data: SeqRow[] };
}

export default function Index({ sequences }: IndexProps) {
    const { data, setData, put, processing, errors } = useForm<Record<number, { prefix: string; next_number: string; padding: string; year_suffix: boolean }>>({});

    return (
        <AdminLayout title="Number Sequences">
            <div className="space-y-6">
                <div>
                    <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Number Sequences</h2>
                    <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Centralized code generation for entities.</p>
                </div>
                <Card><CardContent>
                    <Table>
                        <THead><TR><TH>Entity</TH><TH>Prefix</TH><TH>Next #</TH><TH>Padding</TH><TH>Year</TH></TR></THead>
                        <TBody>
                            {sequences.data.map((s) => (
                                <TR key={s.id}>
                                    <TD><Badge variant="neutral">{s.entity_type}</Badge></TD>
                                    <TD className="font-mono">{s.prefix}</TD>
                                    <TD>{s.next_number}</TD>
                                    <TD>{s.padding}</TD>
                                    <TD>{s.year_suffix ? 'Yes' : 'No'}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </CardContent></Card>
            </div>
        </AdminLayout>
    );
}
