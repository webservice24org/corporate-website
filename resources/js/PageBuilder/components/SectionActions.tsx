import { useState } from "react";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";

import {
    ArrowDown,
    ArrowUp,
    Copy,
    MoreHorizontal,
    Pencil,
    Trash2,
} from "lucide-react";

import { Button } from "@/Components/ui/button";

import {
    DropdownMenu,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from "@/Components/ui/alert-dialog";

import type { BuilderSection } from "../types";

type Props = {
    pageId: number;

    section: BuilderSection;

    index: number;
    totalSections: number;

    onEdit: () => void;

    onDeleted?: () => void;
};

export default function SectionActions({
    pageId,
    section,
    index,
    totalSections,
    onEdit,
    onDeleted,
}: Props) {
    const [deleteOpen, setDeleteOpen] =
        useState(false);

    const [deleting, setDeleting] =
        useState(false);

    const [processingAction, setProcessingAction] =
        useState(false);

    const request = (
        url: string,
    ) => {
        router.post(
            url,
            {},
            {
                preserveScroll: true,

                onStart: () => {
                    setProcessingAction(true);
                },

                onError: () => {
                    toast.error(
                        "Unable to update section.",
                    );
                },

                onFinish: () => {
                    setProcessingAction(false);
                },
            },
        );
    };

    const duplicate = () => {
        request(
            `/admin/pages/${pageId}/sections/${section.id}/duplicate`,
        );
    };

    const moveUp = () => {
        request(
            `/admin/pages/${pageId}/sections/${section.id}/move-up`,
        );
    };

    const moveDown = () => {
        request(
            `/admin/pages/${pageId}/sections/${section.id}/move-down`,
        );
    };

    const deleteSection = () => {
        router.delete(
            `/admin/pages/${pageId}/sections/${section.id}`,
            {
                preserveScroll: true,

                onStart: () => {
                    setDeleting(true);
                },

                onSuccess: () => {
                    setDeleteOpen(false);

                    onDeleted?.();
                },

                onError: () => {
                    toast.error(
                        "Unable to delete section.",
                    );
                },

                onFinish: () => {
                    setDeleting(false);
                },
            },
        );
    };

    const canMoveUp = index > 0;

    const canMoveDown =
        index < totalSections - 1;

    return (
        <>
            <Button
                variant="ghost"
                size="icon-sm"
                aria-label="Edit section"
                onPress={onEdit}
            >
                <Pencil />
            </Button>

            <DropdownMenuTrigger>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Section actions"
                >
                    <MoreHorizontal />
                </Button>

                <DropdownMenu placement="bottom end">
                    <DropdownMenuLabel>
                        Section Actions
                    </DropdownMenuLabel>

                    <DropdownMenuGroup>
                        <DropdownMenuItem
                            onAction={onEdit}
                        >
                            <Pencil />
                            Edit
                        </DropdownMenuItem>

                        <DropdownMenuItem
                            onAction={duplicate}
                            isDisabled={
                                processingAction
                            }
                        >
                            <Copy />
                            Duplicate
                        </DropdownMenuItem>
                    </DropdownMenuGroup>

                    <DropdownMenuSeparator />

                    <DropdownMenuGroup>
                        <DropdownMenuItem
                            onAction={moveUp}
                            isDisabled={
                                !canMoveUp ||
                                processingAction
                            }
                        >
                            <ArrowUp />
                            Move Up
                        </DropdownMenuItem>

                        <DropdownMenuItem
                            onAction={moveDown}
                            isDisabled={
                                !canMoveDown ||
                                processingAction
                            }
                        >
                            <ArrowDown />
                            Move Down
                        </DropdownMenuItem>
                    </DropdownMenuGroup>

                    <DropdownMenuSeparator />

                    <DropdownMenuItem
                        variant="destructive"
                        onAction={() =>
                            setDeleteOpen(true)
                        }
                    >
                        <Trash2 />
                        Delete
                    </DropdownMenuItem>
                </DropdownMenu>
            </DropdownMenuTrigger>

            <AlertDialogTrigger
                isOpen={deleteOpen}
                onOpenChange={setDeleteOpen}
            >
                <AlertDialog>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete section?
                        </AlertDialogTitle>

                        <AlertDialogDescription>
                            Are you sure you want to
                            permanently delete
                            &quot;
                            {section.name ??
                                section.type}
                            &quot;? This action cannot
                            be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>

                    <AlertDialogFooter>
                        <AlertDialogCancel
                            isDisabled={deleting}
                        >
                            Cancel
                        </AlertDialogCancel>

                        <AlertDialogAction
                            variant="destructive"
                            onPress={deleteSection}
                            isDisabled={deleting}
                        >
                            {deleting
                                ? "Deleting..."
                                : "Delete Section"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialog>
            </AlertDialogTrigger>
        </>
    );
}