import {
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/Components/ui/alert-dialog";

import {
    Trash2,
} from "lucide-react";

type DeleteConfirmDialogProps = {
    open: boolean;

    onOpenChange: (
        open: boolean,
    ) => void;

    onConfirm: () => void;

    title?: string;

    description?: string;

    itemName?: string;

    confirmLabel?: string;

    cancelLabel?: string;

    processing?: boolean;
};

export default function DeleteConfirmDialog({
    open,
    onOpenChange,
    onConfirm,
    title = "Delete item?",
    description,
    itemName,
    confirmLabel = "Delete",
    cancelLabel = "Cancel",
    processing = false,
}: DeleteConfirmDialogProps) {
    const defaultDescription =
        itemName
            ? `Are you sure you want to delete "${itemName}"? This action cannot be undone.`
            : "Are you sure you want to delete this item? This action cannot be undone.";

    return (
        <AlertDialogContent
            isOpen={open}
            onOpenChange={
                onOpenChange
            }
        >
            <AlertDialogHeader>
                <div className="mb-2 flex size-11 items-center justify-center rounded-full bg-red-100 text-red-600">
                    <Trash2 className="size-5" />
                </div>

                <AlertDialogTitle>
                    {title}
                </AlertDialogTitle>

                <AlertDialogDescription>
                    {description ??
                        defaultDescription}
                </AlertDialogDescription>
            </AlertDialogHeader>

            <AlertDialogFooter>
                <AlertDialogCancel
                    isDisabled={
                        processing
                    }
                >
                    {cancelLabel}
                </AlertDialogCancel>

                <AlertDialogAction
                    onPress={
                        onConfirm
                    }
                    isDisabled={
                        processing
                    }
                    className="
                        bg-red-600
                        text-white
                        hover:bg-red-700
                        focus-visible:ring-red-600
                    "
                >
                    {processing
                        ? "Deleting..."
                        : confirmLabel}
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    );
}