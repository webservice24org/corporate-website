import { useState } from "react";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";

import {
    Blocks,
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

import type { Page } from "./columns";

type Props = {
    page: Page;
};

export default function PageActions({ page }: Props) {
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const copySlug = async () => {
        try {
            await navigator.clipboard.writeText(page.slug);

            toast.success("Slug copied.");
        } catch {
            toast.error("Unable to copy slug.");
        }
    };

    const deletePage = () => {
        router.delete(`/admin/pages/${page.id}`, {
            preserveScroll: true,

            onStart: () => {
                setDeleting(true);
            },

            onSuccess: () => {
                setDeleteOpen(false);
            },

            onError: () => {
                toast.error("Unable to delete the page.");
            },

            onFinish: () => {
                setDeleting(false);
            },
        });
    };

    return (
        <>
            <DropdownMenuTrigger>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Actions for ${page.title}`}
                >
                    <MoreHorizontal />
                </Button>

                <DropdownMenu placement="bottom end">
                    <DropdownMenuLabel>
                        Page Actions
                    </DropdownMenuLabel>

                    <DropdownMenuGroup>
                        <DropdownMenuItem
                            onAction={() =>
                                router.visit(
                                    `/admin/pages/${page.id}/builder`,
                                )
                            }
                        >
                            <Blocks />
                            Page Builder
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onAction={() =>
                                router.visit(
                                    `/admin/pages/${page.id}/edit`,
                                )
                            }
                        >
                            <Pencil />
                            Edit
                        </DropdownMenuItem>

                        <DropdownMenuItem
                            onAction={copySlug}
                        >
                            <Copy />
                            Copy slug
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
                            Delete page?
                        </AlertDialogTitle>

                        <AlertDialogDescription>
                            Are you sure you want to delete
                            &quot;{page.title}&quot;? This action
                            cannot be undone.
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
                            onPress={deletePage}
                            isDisabled={deleting}
                        >
                            {deleting
                                ? "Deleting..."
                                : "Delete Page"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialog>
            </AlertDialogTrigger>
        </>
    );
}