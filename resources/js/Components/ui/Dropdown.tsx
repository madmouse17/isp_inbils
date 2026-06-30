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
                    'z-50 min-w-[12rem] rounded-lg border border-surface-200 bg-white p-1 shadow-lg dark:border-surface-800 dark:bg-surface-900',
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

export function DropdownItem({ onClick, href = '#', disabled, destructive, children, className }: DropdownItemProps) {
    return (
        <MenuItem>
            <Button
                as="a"
                href={href}
                onClick={onClick}
                disabled={disabled}
                className={cn(
                    'flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    destructive
                        ? 'text-danger hover:bg-red-50 dark:hover:bg-red-900/20'
                        : 'text-surface-700 hover:bg-surface-100 dark:text-surface-300 dark:hover:bg-surface-800',
                    disabled && 'opacity-40 cursor-not-allowed',
                    className,
                )}
            >
                {children}
            </Button>
        </MenuItem>
    );
}

export function DropdownSeparator() {
    return <MenuSection className="my-1 h-px bg-surface-200 dark:bg-surface-800" />;
}

export function DropdownTrigger({ children }: { children: React.ReactNode }) {
    return <Fragment>{children}</Fragment>;
}
