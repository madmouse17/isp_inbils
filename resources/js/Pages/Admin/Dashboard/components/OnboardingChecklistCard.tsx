import { Link } from '@inertiajs/react';
import { CheckCircle2, Circle } from 'lucide-react';
import { Card, Progress } from '@/Components/ui';
import type { OnboardingChecklist } from '@/types/dashboard';

interface OnboardingChecklistCardProps {
    onboarding: OnboardingChecklist;
}

/**
 * What the company still has to set up. A step the data already proves is a plain checked row; one still open is a
 * link to the page that finishes it, so the open work is what stands out. The list is not numbered: the order is free.
 */
export function OnboardingChecklistCard({ onboarding }: OnboardingChecklistCardProps) {
    const { steps, prefixes, done, total } = onboarding;

    return (
        <Card className="flex flex-col gap-4 p-5">
            <Progress
                value={(done / total) * 100}
                label={`${done} dari ${total} langkah selesai`}
            />
            <p className="text-sm text-muted-foreground">
                Langkah tercentang otomatis begitu datanya ada. Urutannya bebas.
            </p>
            <p className="text-sm text-muted-foreground">
                Kode dokumen dimulai dengan{' '}
                {prefixes.map(({ label, prefix }) => `${label} ${prefix}`).join(', ')}.
            </p>
            <ul aria-label="Langkah penyiapan" role="list" className="grid gap-2 sm:grid-cols-2">
                {steps.map((step) => (
                    <li key={step.key}>
                        {step.done ? (
                            <div className="flex min-h-11 items-center gap-3 rounded-md border border-transparent px-3 text-sm text-muted-foreground">
                                <CheckCircle2
                                    className="size-5 shrink-0 text-(--status-success)"
                                    aria-hidden="true"
                                />
                                <span>
                                    {step.label}
                                    <span className="sr-only">, selesai</span>
                                </span>
                            </div>
                        ) : (
                            <Link
                                href={step.href}
                                className="flex min-h-11 items-center gap-3 rounded-md border border-input px-3 text-sm font-medium text-foreground transition-colors hover:bg-accent/30 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-hidden focus-visible:ring-inset active:bg-accent/60 motion-reduce:transition-none"
                            >
                                <Circle
                                    className="size-5 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span>
                                    {step.label}
                                    <span className="sr-only">, belum selesai</span>
                                </span>
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </Card>
    );
}
