import { useState } from 'react';
import { Plus } from 'lucide-react';
import { Button, Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatDateTime } from '@/lib/format';
import { SubsidiaryRequestDialog } from './SubsidiaryRequestDialog';

/** The company's open request for a subsidiary: what its admin typed, and when it was sent. */
export interface SubsidiaryRequestSummary {
    name: string;
    code: string | null;
    note: string | null;
    requested_at: string | null;
}

interface SubsidiaryRequestCardProps {
    /** The request that waits for the operator, or null when the company has none. */
    request: SubsidiaryRequestSummary | null;
}

/**
 * "Anak perusahaan" on the company profile (K34): the admin asks the platform operator for one, and sees that the
 * request waits. One request at a time, so a pending one replaces the button with its text.
 */
export function SubsidiaryRequestCard({ request }: SubsidiaryRequestCardProps) {
    const company = useCompany();
    const [asking, setAsking] = useState(false);

    return (
        <Card>
            <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <CardTitle>Anak perusahaan</CardTitle>
                    <CardDescription>
                        Unit di bawah perusahaan ini dengan data yang tetap terpisah. Operator
                        platform yang membuatnya.
                    </CardDescription>
                </div>
                {request ? null : (
                    <Button
                        type="button"
                        size="sm"
                        leftIcon={<Plus className="size-4" aria-hidden="true" />}
                        onClick={() => setAsking(true)}
                    >
                        Minta anak perusahaan
                    </Button>
                )}
            </CardHeader>
            {request ? (
                <CardContent className="flex flex-col gap-1 text-sm" role="status">
                    <p className="font-medium break-words">
                        Permintaan {request.name} sedang ditinjau operator.
                    </p>
                    {request.code ? (
                        <p className="text-muted-foreground">Kode usulan: {request.code}</p>
                    ) : null}
                    {request.note ? (
                        <p className="break-words whitespace-pre-line text-muted-foreground">
                            {request.note}
                        </p>
                    ) : null}
                    {request.requested_at ? (
                        <p className="text-muted-foreground">
                            Dikirim {formatDateTime(request.requested_at, company)}.
                        </p>
                    ) : null}
                </CardContent>
            ) : null}
            {asking ? <SubsidiaryRequestDialog onClose={() => setAsking(false)} /> : null}
        </Card>
    );
}
