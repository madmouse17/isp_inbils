import { ShieldCheck } from 'lucide-react';

export function OperatorBand() {
    return (
        <div className="flex min-h-9 items-center gap-2 bg-warning px-4 py-1.5 text-sm font-medium text-white md:px-6">
            <ShieldCheck className="size-4 shrink-0" aria-hidden="true" />
            <span>Panel operator</span>
            <span className="hidden font-normal text-white sm:inline">
                Akses lintas perusahaan tercatat di audit.
            </span>
        </div>
    );
}
