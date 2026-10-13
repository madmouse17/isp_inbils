import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Building2, ChevronsUpDown } from 'lucide-react';
import { Button, Spinner } from '@/Components/ui';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import type { PageProps } from '@/types';

interface CompanySwitcherProps {
    /** Runs once the switch went through, so a mobile drawer can close itself. */
    onSwitched?: () => void;
}

/**
 * The companies the user may act in. The server decides who gets one (`canSwitch`) and what each
 * entry is; the POST is checked again there, so a forged id changes nothing.
 */
export function CompanySwitcher({ onSwitched }: CompanySwitcherProps) {
    const { canSwitch, companies = [] } = usePage<PageProps>().props;
    const [switching, setSwitching] = useState(false);
    const current = companies.find((company) => company.is_current);

    if (!canSwitch || !current) {
        return null;
    }

    const switchTo = (companyId: string) => {
        if (switching || Number(companyId) === current.id) {
            return;
        }

        router.post(
            route('admin.company.switch'),
            { company_id: Number(companyId) },
            {
                onStart: () => setSwitching(true),
                onFinish: () => setSwitching(false),
                onSuccess: () => onSwitched?.(),
            },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    // Not `loading`: a disabled trigger cannot take back focus when the menu closes.
                    aria-busy={switching}
                    leftIcon={
                        switching ? (
                            <Spinner size="sm" className="shrink-0" />
                        ) : (
                            <Building2
                                className="h-5 w-5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        )
                    }
                    rightIcon={
                        <ChevronsUpDown
                            className="h-4 w-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    }
                    aria-label={`Ganti perusahaan, saat ini: ${current.name}`}
                    size="auto"
                    className="w-full justify-start gap-3 px-3 text-left font-normal"
                >
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium">{current.name}</span>
                        <span className="block text-xs text-muted-foreground">
                            Ganti perusahaan
                        </span>
                    </span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="w-(--radix-dropdown-menu-trigger-width) min-w-56"
            >
                <DropdownMenuLabel>Ganti perusahaan</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup
                    aria-label="Perusahaan"
                    value={String(current.id)}
                    onValueChange={switchTo}
                >
                    {companies.map((company) => (
                        <DropdownMenuRadioItem
                            key={company.id}
                            value={String(company.id)}
                            className="min-h-11 py-2 data-highlighted:ring-2 data-highlighted:ring-ring data-highlighted:ring-inset"
                        >
                            <span className="flex min-w-0 flex-col">
                                <span className="truncate font-medium">{company.name}</span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {company.is_default ? `${company.code}, bawaan` : company.code}
                                </span>
                            </span>
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
