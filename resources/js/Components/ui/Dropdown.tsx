import { Menu, MenuButton, MenuItems, MenuItem, MenuSection, Button } from '@headlessui/react';
import { cn } from '@/lib/utils';
import { Fragment } from 'react';

interface DropdownProps {
    trigger: React.ReactNode;
    align?: 'left' | 'right';
    children: React.ReactNode;
    className?: string;
}

export function Dropdown({ trigger, align = 'right', children, className }: DropdownProps) {
    return (
        <Menu as="div" className={cn('relative inline-block text-left', className)}>
            <MenuButton as={Fragment}>{trigger}</MenuButton>
            <MenuItems
                anchor={align === 'right' ? 'bottom end' : 'bottom start'}
                transition
                className={cn(
                    'z-50 min-w-[12rem] rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-lg',
                    'transition data-[closed]:opacity-0 data-[enter]:duration-150 data-[leave]:duration-100',
                )}
            >
                {children}
            </MenuItems>
        </Menu>
    );
}

interface DropdownItemProps {
    onClick?: () => void;
    href?: string;
    disabled?: boolean;
    destructive?: boolean;
    children: React.ReactNode;
    className?: string;
}

export function DropdownItem({
    onClick,
    href,
    disabled,
    destructive,
    children,
    className,
}: DropdownItemProps) {
    const classes = cn(
        'flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
        destructive
            ? 'text-destructive hover:bg-destructive/10'
            : 'text-foreground hover:bg-accent hover:text-accent-foreground',
        disabled && 'cursor-not-allowed opacity-40',
        className,
    );

    return (
        <MenuItem>
            {href ? (
                <Button as="a" href={href} onClick={onClick} disabled={disabled} className={classes}>
                    {children}
                </Button>
            ) : (
                <Button type="button" onClick={onClick} disabled={disabled} className={classes}>
                    {children}
                </Button>
            )}
        </MenuItem>
    );
}

export function DropdownSeparator() {
    return <MenuSection className="my-1 h-px bg-border" />;
}

export function DropdownTrigger({ children }: { children: React.ReactNode }) {
    return <Fragment>{children}</Fragment>;
}
