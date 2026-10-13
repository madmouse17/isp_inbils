import * as React from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from './dropdown-menu';

export {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuGroup,
    DropdownMenuPortal,
    DropdownMenuSub,
    DropdownMenuRadioGroup,
} from './dropdown-menu';

type DropdownProps = {
    trigger: React.ReactNode;
    children: React.ReactNode;
};

/** A menu opened by `trigger`: the short form of the DropdownMenu parts, which hold the one implementation. */
function Dropdown({ trigger, children }: DropdownProps) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
            <DropdownMenuContent>{children}</DropdownMenuContent>
        </DropdownMenu>
    );
}

const DropdownItem = DropdownMenuItem;
const DropdownSeparator = DropdownMenuSeparator;

export { Dropdown, DropdownItem, DropdownSeparator };
