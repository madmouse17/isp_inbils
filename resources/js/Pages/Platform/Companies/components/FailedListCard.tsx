import { useEffect, useState } from 'react';
import axios from 'axios';
import { DataTable, type DataTableColumn } from '@/Components/composite';
import { Button, Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui';
import { toPagination } from '@/lib/pagination';
import type { PlatformFailedPage } from '@/types/platform';

/** The detail page shows the last week; the support endpoint answers for this many days. */
const RECENT_DAYS = 7;
const FULL_DAYS = 30;

interface FailedListCardProps<T> {
    title: string;
    /** Lower case, plural-free: "job gagal". It fills the descriptions and the empty states. */
    itemLabel: string;
    /** The newest rows of the last week, from the page props. */
    recent: T[];
    columns: DataTableColumn<T>[];
    /** Columns of the 30-day list when they differ from `columns`. */
    fullColumns?: DataTableColumn<T>[];
    /** The support endpoint of the 30-day list (JSON, paginated). */
    url: string;
}

type Answer<T> = { key: string; page: PlatformFailedPage<T> | null };

function useFailedPage<T>(url: string, page: number, perPage: number, enabled: boolean) {
    const [attempt, setAttempt] = useState(0);
    const [answer, setAnswer] = useState<Answer<T> | null>(null);
    const key = `${url}|${page}|${perPage}|${attempt}`;

    useEffect(() => {
        if (!enabled) return;

        const controller = new AbortController();

        axios
            .get<PlatformFailedPage<T>>(url, {
                params: { page, per_page: perPage },
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
            .then((response) => setAnswer({ key, page: response.data }))
            .catch((error: unknown) => {
                if (!axios.isCancel(error)) setAnswer({ key, page: null });
            });

        return () => controller.abort();
    }, [enabled, key, url, page, perPage]);

    return {
        loading: enabled && answer?.key !== key,
        failed: answer?.key === key && answer.page === null,
        list: answer?.page ?? null,
        retry: () => setAttempt((count) => count + 1),
    };
}

/** A card of failed rows: the week from the page props, or the 30 days the support endpoint pages through. */
export function FailedListCard<T>({
    title,
    itemLabel,
    recent,
    columns,
    fullColumns,
    url,
}: FailedListCardProps<T>) {
    const [full, setFull] = useState(false);
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);
    const { loading, failed, list, retry } = useFailedPage<T>(url, page, perPage, full);
    const days = full ? (list?.window_days ?? FULL_DAYS) : RECENT_DAYS;
    const empty = {
        emptyTitle: `Tidak ada ${itemLabel}`,
        emptyDescription: `Tidak ada ${itemLabel} pada perusahaan ini dalam ${days} hari terakhir.`,
        itemLabel,
    };

    return (
        <Card>
            <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <CardTitle>{title}</CardTitle>
                    <CardDescription>
                        {full
                            ? `Semua ${itemLabel} dalam ${days} hari terakhir.`
                            : `Sepuluh ${itemLabel} terbaru dalam ${RECENT_DAYS} hari.`}
                    </CardDescription>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        setFull(!full);
                        setPage(1);
                    }}
                >
                    {full ? 'Kembali ke ringkasan' : `Lihat ${FULL_DAYS} hari terakhir`}
                </Button>
            </CardHeader>
            <CardContent>
                {full ? (
                    <DataTable
                        {...empty}
                        columns={fullColumns ?? columns}
                        pagination={list ? toPagination(list) : null}
                        onPageChange={setPage}
                        onPerPageChange={(value) => {
                            setPerPage(value);
                            setPage(1);
                        }}
                        loading={loading}
                        error={
                            failed
                                ? `Daftar ${itemLabel} ${FULL_DAYS} hari tidak dapat dimuat. Periksa koneksi, lalu coba lagi.`
                                : undefined
                        }
                        onRetry={retry}
                    />
                ) : (
                    <DataTable {...empty} columns={columns} rows={recent} />
                )}
            </CardContent>
        </Card>
    );
}
