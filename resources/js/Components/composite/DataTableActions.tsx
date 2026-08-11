import { Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';

export interface DataTableActionsProps {
    showHref?: string;
    onShow?: () => void;
    editHref?: string;
    onEdit?: () => void;
    deleteHref?: string;
    onDelete?: () => void;
    deleteMessage?: string;
}

export function DataTableActions({
    showHref,
    onShow,
    editHref,
    onEdit,
    deleteHref,
    onDelete,
    deleteMessage = 'Delete this item?',
}: DataTableActionsProps) {
    return (
        <div className="flex flex-wrap gap-1">
            {showHref ? (
                <Button
                    asChild
                    variant="ghost"
                    size="sm"
                    className="bg-brand-600 text-white hover:bg-brand-700 hover:text-white dark:bg-brand-500 dark:hover:bg-brand-600"
                >
                    <Link href={showHref}>Show</Link>
                </Button>
            ) : onShow ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="bg-brand-600 text-white hover:bg-brand-700 hover:text-white dark:bg-brand-500 dark:hover:bg-brand-600"
                    onClick={onShow}
                >
                    Show
                </Button>
            ) : null}
            {editHref ? (
                <Button
                    asChild
                    variant="ghost"
                    size="sm"
                    className="bg-warning text-white hover:bg-warning/90 hover:text-white"
                >
                    <Link href={editHref}>Edit</Link>
                </Button>
            ) : onEdit ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="bg-warning text-white hover:bg-warning/90 hover:text-white"
                    onClick={onEdit}
                >
                    Edit
                </Button>
            ) : null}
            {deleteHref || onDelete ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="bg-danger text-white hover:bg-danger/90 hover:text-white"
                    onClick={() => {
                        if (window.confirm(deleteMessage)) {
                            if (onDelete) {
                                onDelete();
                                return;
                            }

                            if (deleteHref) {
                                router.delete(deleteHref, { preserveScroll: true });
                            }
                        }
                    }}
                >
                    Delete
                </Button>
            ) : null}
        </div>
    );
}
