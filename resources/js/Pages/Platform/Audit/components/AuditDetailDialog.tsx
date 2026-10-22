import type { ReactNode } from 'react';
import { EmptyCell, EnumBadge, Modal } from '@/Components/ui';
import { formatDateTime } from '@/lib/format';
import { formatPropertyValue } from '@/lib/platform';
import { PLATFORM_LOG } from '@/lib/statuses';
import type { PlatformAuditEntry } from '@/types/platform';

interface AuditDetailDialogProps {
    entry: PlatformAuditEntry;
    onClose: () => void;
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 font-medium break-words">{children}</dd>
        </div>
    );
}

export function AuditDetailDialog({ entry, onClose }: AuditDetailDialogProps) {
    const properties = Object.entries(entry.properties);

    return (
        <Modal
            open
            onClose={onClose}
            title={entry.text}
            size="lg"
        >
            <div className="space-y-5">
                <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <Fact label="Waktu">{formatDateTime(entry.created_at)}</Fact>
                    <Fact label="Sumber">
                        <EnumBadge map={PLATFORM_LOG} value={entry.log_name} />
                    </Fact>
                    <Fact label="Pelaku">
                        {entry.causer ? (
                            `${entry.causer.name ?? 'Tidak diketahui'} (${
                                entry.causer.type === 'platform_admin'
                                    ? 'operator platform'
                                    : 'pengguna'
                            })`
                        ) : (
                            <EmptyCell />
                        )}
                    </Fact>
                    <Fact label="Perusahaan">
                        {entry.company ? (
                            `${entry.company.name ?? entry.company.code} (${entry.company.code})`
                        ) : (
                            <EmptyCell />
                        )}
                    </Fact>
                    <Fact label="Subjek">
                        {entry.subject ? (
                            `${entry.subject.type}${entry.subject_label ? ` ${entry.subject_label}` : ` #${entry.subject.id}`}`
                        ) : (
                            <EmptyCell />
                        )}
                    </Fact>
                    <Fact label="Kode kegiatan">
                        <code className="text-xs">{entry.description}</code>
                    </Fact>
                    <Fact label="Kelompok kegiatan">
                        {entry.batch_uuid ? (
                            <code className="text-xs">{entry.batch_uuid}</code>
                        ) : (
                            <EmptyCell />
                        )}
                    </Fact>
                </dl>
                <section aria-labelledby="audit-properties">
                    <h3 id="audit-properties" className="text-sm font-semibold">
                        Properti tercatat
                    </h3>
                    {properties.length === 0 ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            Kegiatan ini tidak menyimpan properti.
                        </p>
                    ) : (
                        <dl className="mt-2 divide-y rounded-md border text-sm">
                            {properties.map(([key, value]) => (
                                <div
                                    key={key}
                                    className="grid gap-1 px-3 py-2 sm:grid-cols-[10rem_1fr] sm:gap-4"
                                >
                                    <dt className="font-mono text-xs text-muted-foreground">
                                        {key}
                                    </dt>
                                    <dd className="font-mono text-xs break-all">
                                        {formatPropertyValue(value)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </section>
            </div>
        </Modal>
    );
}
