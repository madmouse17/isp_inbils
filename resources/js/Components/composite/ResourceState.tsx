import type { ReactNode } from 'react';
import { usePermission } from '@/hooks/usePermission';
import { Link } from '@inertiajs/react';
import { AlertCircle, Inbox, ShieldAlert } from 'lucide-react';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/Components/ui/empty';
import { Button } from '@/Components/ui/Button';
import { Skeleton } from '@/Components/ui/Skeleton';
import { cn } from '@/lib/utils';

// ── Loading ──────────────────────────────────────────────────────────

type LoadingStateProps = {
    /** full = page block; section = compact; rows = table-ish */
    variant?: 'full' | 'section' | 'rows';
    rows?: number;
    className?: string;
};

export function LoadingState({ variant = 'section', rows = 5, className }: LoadingStateProps) {
    if (variant === 'rows') {
        return (
            <div className={cn('space-y-3', className)} role="status" aria-label="Loading">
                {Array.from({ length: rows }, (_, i) => (
                    <div key={i} className="flex items-center gap-3">
                        <Skeleton className="h-10 w-10 shrink-0 rounded-md" />
                        <div className="flex-1 space-y-2">
                            <Skeleton className="h-4 w-1/3" />
                            <Skeleton className="h-3 w-1/2" />
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    if (variant === 'full') {
        return (
            <div
                className={cn(
                    'flex min-h-[40vh] flex-col items-center justify-center gap-4',
                    className,
                )}
                role="status"
                aria-label="Loading"
            >
                <div className="w-full max-w-md space-y-3">
                    <Skeleton className="mx-auto h-8 w-1/2" />
                    <Skeleton className="mx-auto h-4 w-3/4" />
                    <Skeleton className="h-32 w-full" />
                    <div className="flex gap-2">
                        <Skeleton className="h-9 flex-1" />
                        <Skeleton className="h-9 flex-1" />
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className={cn('space-y-3 py-6', className)} role="status" aria-label="Loading">
            <Skeleton className="h-6 w-1/3" />
            <Skeleton className="h-4 w-2/3" />
            <Skeleton className="h-24 w-full" />
        </div>
    );
}

// ── Error ────────────────────────────────────────────────────────────

type ErrorStateProps = {
    title?: string;
    description?: string;
    onRetry?: () => void;
    retryLabel?: string;
    action?: ReactNode;
    className?: string;
};

export function ErrorState({
    title = 'Something went wrong',
    description = 'An unexpected error occurred. Try again or contact support.',
    onRetry,
    retryLabel = 'Try again',
    action,
    className,
}: ErrorStateProps) {
    return (
        <Empty className={cn('border border-dashed', className)}>
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <AlertCircle className="text-destructive" />
                </EmptyMedia>
                <EmptyTitle>{title}</EmptyTitle>
                <EmptyDescription>{description}</EmptyDescription>
            </EmptyHeader>
            {(onRetry || action) && (
                <EmptyContent>
                    {action}
                    {onRetry ? (
                        <Button type="button" variant="outline" onClick={onRetry}>
                            {retryLabel}
                        </Button>
                    ) : null}
                </EmptyContent>
            )}
        </Empty>
    );
}

// ── Forbidden ────────────────────────────────────────────────────────

type ForbiddenStateProps = {
    title?: string;
    description?: string;
    /** href for back link; omit to hide */
    backHref?: string;
    backLabel?: string;
    action?: ReactNode;
    className?: string;
};

export function ForbiddenState({
    title = 'Access denied',
    description = 'You do not have permission to view this resource.',
    backHref = '/',
    backLabel = 'Back to home',
    action,
    className,
}: ForbiddenStateProps) {
    return (
        <Empty className={cn('border border-dashed', className)}>
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <ShieldAlert />
                </EmptyMedia>
                <EmptyTitle>{title}</EmptyTitle>
                <EmptyDescription>{description}</EmptyDescription>
            </EmptyHeader>
            {(backHref || action) && (
                <EmptyContent>
                    {action}
                    {backHref ? (
                        <Link
                            href={backHref}
                            className="inline-flex h-9 items-center justify-center rounded-md border border-input bg-background px-4 text-sm font-medium shadow-sm hover:bg-accent hover:text-accent-foreground"
                        >
                            {backLabel}
                        </Link>
                    ) : null}
                </EmptyContent>
            )}
        </Empty>
    );
}

// ── Empty (re-export shape via primitives) ───────────────────────────

type ResourceEmptyProps = {
    title?: string;
    description?: string;
    action?: ReactNode;
    icon?: ReactNode;
    className?: string;
};

export function ResourceEmpty({
    title = 'No data',
    description = 'Nothing to show yet.',
    action,
    icon,
    className,
}: ResourceEmptyProps) {
    return (
        <Empty className={cn('border border-dashed', className)}>
            <EmptyHeader>
                <EmptyMedia variant="icon">{icon ?? <Inbox />}</EmptyMedia>
                <EmptyTitle>{title}</EmptyTitle>
                {description ? <EmptyDescription>{description}</EmptyDescription> : null}
            </EmptyHeader>
            {action ? <EmptyContent>{action}</EmptyContent> : null}
        </Empty>
    );
}

// ── Switch ───────────────────────────────────────────────────────────

export type ResourceStatus = 'loading' | 'empty' | 'error' | 'forbidden' | 'ready';

type ResourceStateProps = {
    status: ResourceStatus;
    children?: ReactNode;
    className?: string;
    /** loading */
    loadingVariant?: LoadingStateProps['variant'];
    loadingRows?: number;
    /** empty */
    emptyTitle?: string;
    emptyDescription?: string;
    emptyAction?: ReactNode;
    emptyIcon?: ReactNode;
    /** error */
    errorTitle?: string;
    errorDescription?: string;
    onRetry?: () => void;
    retryLabel?: string;
    errorAction?: ReactNode;
    /** forbidden */
    forbiddenTitle?: string;
    forbiddenDescription?: string;
    backHref?: string;
    backLabel?: string;
    forbiddenAction?: ReactNode;
};

export function ResourceState({
    status,
    children,
    className,
    loadingVariant,
    loadingRows,
    emptyTitle,
    emptyDescription,
    emptyAction,
    emptyIcon,
    errorTitle,
    errorDescription,
    onRetry,
    retryLabel,
    errorAction,
    forbiddenTitle,
    forbiddenDescription,
    backHref,
    backLabel,
    forbiddenAction,
}: ResourceStateProps) {
    switch (status) {
        case 'loading':
            return (
                <LoadingState variant={loadingVariant} rows={loadingRows} className={className} />
            );
        case 'empty':
            return (
                <ResourceEmpty
                    title={emptyTitle}
                    description={emptyDescription}
                    action={emptyAction}
                    icon={emptyIcon}
                    className={className}
                />
            );
        case 'error':
            return (
                <ErrorState
                    title={errorTitle}
                    description={errorDescription}
                    onRetry={onRetry}
                    retryLabel={retryLabel}
                    action={errorAction}
                    className={className}
                />
            );
        case 'forbidden':
            return (
                <ForbiddenState
                    title={forbiddenTitle}
                    description={forbiddenDescription}
                    backHref={backHref}
                    backLabel={backLabel}
                    action={forbiddenAction}
                    className={className}
                />
            );
        case 'ready':
            return <>{children}</>;
    }
}

type PermissionGateProps = {
    /** Single permission slug */
    permission?: string;
    /** Multiple permission slugs */
    permissions?: string[];
    /** When true with permissions[], require any; default all */
    any?: boolean;
    children: ReactNode;
    fallback?: ReactNode;
} & ForbiddenStateProps;

/** Hide children when the current user lacks permission; show ForbiddenState (or fallback). */
export function PermissionGate({
    permission,
    permissions,
    any = false,
    children,
    fallback,
    ...forbidden
}: PermissionGateProps) {
    const { can, canAny } = usePermission();

    let allowed = true;
    if (permission) {
        allowed = can(permission);
    } else if (permissions && permissions.length > 0) {
        allowed = any ? canAny(permissions) : permissions.every((p) => can(p));
    }

    if (allowed) {
        return <>{children}</>;
    }

    if (fallback !== undefined) {
        return <>{fallback}</>;
    }

    return <ForbiddenState {...forbidden} />;
}
