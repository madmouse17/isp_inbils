import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Button, Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui';
import { ConfirmActionDialog } from '@/Components/composite/platform/ConfirmActionDialog';
import { usePlatformAction } from '@/Components/composite/platform/usePlatformAction';
import { formatDateTime } from '@/lib/format';
import type {
    PlatformCompanyUser,
    PlatformSubsidiaryRequest,
    PlatformTenantProfile,
} from '@/types/platform';
import { SubsidiaryDialog } from './SubsidiaryDialog';

interface SubsidiaryRequestsCardProps {
    requests: PlatformSubsidiaryRequest[];
    /**
     * On a tenant's page: the tenant and its admins, so the subsidiary is created in place. The dashboard passes
     * none, and each of its rows opens the page of its tenant with the dialog ready.
     */
    scope?: { tenant: PlatformTenantProfile; admins: PlatformCompanyUser[] };
}

/**
 * The requests for a subsidiary that wait for an operator. It is there only while something waits, so the dashboard
 * and the tenant page carry no empty card: a request is an item to act on, not a table to browse.
 */
export function SubsidiaryRequestsCard({ requests, scope }: SubsidiaryRequestsCardProps) {
    const { url } = usePage();
    // The dashboard row links here with `?permintaan=<id>`: that request's dialog opens ready.
    const wanted = new URLSearchParams(url.split('?')[1] ?? '').get('permintaan');
    const [creating, setCreating] = useState<PlatformSubsidiaryRequest | null>(() =>
        scope ? (requests.find((request) => request.id === wanted) ?? null) : null,
    );
    const [rejecting, setRejecting] = useState<PlatformSubsidiaryRequest | null>(null);
    const { processing, errors, post, clearErrors } = usePlatformAction();
    const closeRejection = () => {
        setRejecting(null);
        clearErrors();
    };

    if (requests.length === 0) return null;

    return (
        <Card id="permintaan-anak-perusahaan">
            <CardHeader>
                <CardTitle>Permintaan anak perusahaan</CardTitle>
                <CardDescription>
                    Admin perusahaan meminta anak perusahaan. Buat anak perusahaannya, atau tolak
                    dengan alasan yang dikirim ke pemohon.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="divide-y divide-border">
                    {requests.map((request) => (
                        <li
                            key={request.id}
                            className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div className="flex min-w-0 flex-col gap-1">
                                <p className="font-medium break-words">{request.name}</p>
                                {request.code ? (
                                    <p className="text-sm text-muted-foreground">
                                        Kode usulan: {request.code}
                                    </p>
                                ) : null}
                                {scope ? null : (
                                    <p className="text-sm">
                                        Untuk{' '}
                                        <Link
                                            href={route(
                                                'platform.companies.show',
                                                request.company.id,
                                            )}
                                            className="font-medium text-primary-text hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                        >
                                            {request.company.name}
                                        </Link>{' '}
                                        ({request.company.code})
                                    </p>
                                )}
                                <p className="text-sm text-muted-foreground">
                                    Diminta oleh {request.requester?.name ?? 'admin perusahaan'}
                                    {request.requested_at
                                        ? `, ${formatDateTime(request.requested_at)}`
                                        : ''}
                                </p>
                                {request.note ? (
                                    <p className="text-sm break-words whitespace-pre-line">
                                        {request.note}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex shrink-0 flex-wrap gap-2">
                                {scope ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        leftIcon={<Plus className="size-4" aria-hidden="true" />}
                                        disabled={scope.tenant.status !== 'active'}
                                        onClick={() => setCreating(request)}
                                    >
                                        Buat anak perusahaan
                                    </Button>
                                ) : (
                                    <Button
                                        asChild
                                        size="sm"
                                        leftIcon={<Plus className="size-4" aria-hidden="true" />}
                                    >
                                        <Link
                                            href={route('platform.companies.show', {
                                                company: request.company.id,
                                                permintaan: request.id,
                                            })}
                                        >
                                            Buat anak perusahaan
                                        </Link>
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setRejecting(request)}
                                >
                                    Tolak
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
                {scope && scope.tenant.status !== 'active' ? (
                    <p className="mt-4 text-sm text-muted-foreground">
                        Aktifkan kembali perusahaan sebelum membuat anak perusahaan. Permintaan
                        masih bisa ditolak.
                    </p>
                ) : null}
            </CardContent>
            {scope && creating ? (
                <SubsidiaryDialog
                    tenant={scope.tenant}
                    admins={scope.admins}
                    request={creating}
                    onClose={() => setCreating(null)}
                />
            ) : null}
            {rejecting ? (
                <ConfirmActionDialog
                    title={`Tolak permintaan ${rejecting.name}?`}
                    description="Permintaan ditutup. Alasan yang Anda tulis dicatat di audit dan dikirim lewat email ke pemohon, jadi tulis dengan kalimat yang bisa dipahami admin perusahaan."
                    confirmLabel="Tolak permintaan"
                    reason
                    processing={processing}
                    errors={errors}
                    onConfirmed={(confirmation) =>
                        post(
                            route('platform.companies.subsidiary-requests.reject', {
                                company: rejecting.company.id,
                                subsidiaryRequest: rejecting.id,
                            }),
                            confirmation,
                            closeRejection,
                        )
                    }
                    onClose={closeRejection}
                />
            ) : null}
        </Card>
    );
}
