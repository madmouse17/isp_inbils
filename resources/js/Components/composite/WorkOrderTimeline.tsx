import { CheckCircle2, Circle, CircleDot, XCircle } from 'lucide-react';
import { EnumBadge } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatDateTime } from '@/lib/format';
import { WORK_ORDER_TRIP } from '@/lib/statuses';
import { cn } from '@/lib/utils';
import type { Company } from '@/types';

export type WorkOrderStepKey = 'assigned' | 'en_route' | 'in_progress' | 'installed';

/** One of the four steps of a visit; `at` is set only on a step that was reached (and may still be null). */
export interface WorkOrderStep {
    key: WorkOrderStepKey;
    label: string;
    at: string | null;
}

export interface WorkOrderTimelineProps {
    /** `[]` for a cancelled SPK. */
    steps: WorkOrderStep[];
    /** The last step reached; every step up to and including it is reached. */
    currentStep: WorkOrderStepKey | null;
    /** The SPK status: `rejected` marks the current step as rework, `cancelled` replaces the list. */
    status: string;
    className?: string;
}

type StepState = 'done' | 'current' | 'pending';

function Mark({ state }: { state: StepState }) {
    const className = 'size-6 shrink-0 bg-card';

    if (state === 'done') {
        return (
            <CheckCircle2
                className={cn(className, 'text-emerald-700 dark:text-emerald-400')}
                aria-hidden="true"
            />
        );
    }
    if (state === 'current') {
        return (
            <CircleDot
                className={cn(className, 'text-sky-700 dark:text-sky-400')}
                aria-hidden="true"
            />
        );
    }

    return <Circle className={cn(className, 'text-muted-foreground')} aria-hidden="true" />;
}

function stepText(state: StepState, at: string | null, company: Company | null): string {
    if (state === 'pending') return 'Belum dicapai';

    const time = at ? formatDateTime(at, company) : 'waktu tidak tercatat';
    if (state === 'current') return `Tahap sekarang, ${time}`;

    return time.charAt(0).toUpperCase() + time.slice(1);
}

/**
 * The four steps of a field visit, top to bottom, with the time each was reached in the company's date format.
 * A step is told by its icon and by words, never by colour alone. The steps come from the server
 * (`Modules/SPK/app/Support/TripSteps.php`): this component only draws them.
 */
export function WorkOrderTimeline({
    steps,
    currentStep,
    status,
    className,
}: WorkOrderTimelineProps) {
    const company = useCompany();

    if (status === 'cancelled' || steps.length === 0) {
        return (
            <div
                className={cn('flex items-start gap-3 text-sm', className)}
                role="status"
                aria-label="Tahap kunjungan"
            >
                <XCircle className="size-6 shrink-0 text-muted-foreground" aria-hidden="true" />
                <div>
                    <p className="font-medium text-foreground">Dibatalkan</p>
                    <p className="text-muted-foreground">
                        SPK ini dibatalkan, jadi tidak ada tahap kunjungan.
                    </p>
                </div>
            </div>
        );
    }

    const reached = steps.findIndex((step) => step.key === currentStep);

    return (
        <ol className={className} aria-label="Tahap kunjungan">
            {steps.map((step, index) => {
                // The last step is the end of the visit: reaching it finishes it, there is no "now" after it.
                const state: StepState =
                    reached < 0 || index > reached
                        ? 'pending'
                        : index === reached && step.key !== 'installed'
                          ? 'current'
                          : 'done';
                const last = index === steps.length - 1;

                return (
                    <li
                        key={step.key}
                        className="relative flex gap-3 pb-6 last:pb-0"
                        aria-current={state === 'current' ? 'step' : undefined}
                    >
                        {!last && (
                            <span
                                className={cn(
                                    'absolute top-7 bottom-1 left-3 w-px -translate-x-1/2',
                                    state === 'done' ? 'bg-emerald-600/60' : 'bg-border',
                                )}
                                aria-hidden="true"
                            />
                        )}
                        <Mark state={state} />
                        <div className="space-y-0.5 min-w-0 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <p
                                    className={cn(
                                        'font-medium',
                                        state === 'pending'
                                            ? 'text-muted-foreground'
                                            : 'text-foreground',
                                    )}
                                >
                                    {step.label}
                                </p>
                                {state === 'current' && status === 'rejected' && (
                                    <EnumBadge map={WORK_ORDER_TRIP} value="rework" />
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {state === 'done' && <span className="sr-only">Selesai. </span>}
                                {stepText(state, step.at, company)}
                            </p>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
