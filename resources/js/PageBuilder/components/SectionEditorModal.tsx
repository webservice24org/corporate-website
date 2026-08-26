import {
    useEffect,
    useState,
} from "react";

import { useForm } from "@inertiajs/react";
import toast from "react-hot-toast";

import {
    ArrowLeft,
    Monitor,
    Save,
    Smartphone,
    Tablet,
    X,
} from "lucide-react";

import { Button } from "@/Components/ui/button";

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

import SectionEditor, {
    type EditorForm,
} from "./SectionEditor";

import {
    getSectionDesign,
    getSectionGroup,
} from "../registry";

import type {
    BuilderSection,
} from "../types";

type Props = {
    pageId: number;
    section: BuilderSection;
    onClose: () => void;
};

type PreviewMode =
    | "desktop"
    | "tablet"
    | "mobile";

export default function SectionEditorModal({
    pageId,
    section,
    onClose,
}: Props) {
    const design = getSectionDesign(
        section.type,
        section.variant,
    );

    const group = getSectionGroup(
        section.type,
    );

    const [previewMode, setPreviewMode] =
        useState<PreviewMode>("desktop");

    const [
        discardDialogOpen,
        setDiscardDialogOpen,
    ] = useState(false);

    const {
        data,
        setData,
        put,
        processing,
        errors,
        isDirty,
        reset,
    } = useForm<EditorForm>({
        name:
            section.name ??
            design?.label ??
            group?.label ??
            "Section",

        variant: section.variant,

        settings:
            (section.settings as Record<string, any>) ?? {},

        is_active:
            section.is_active,
    });

    /**
     * Prevent accidental browser refresh / tab close
     * when there are unsaved changes.
     */
    useEffect(() => {
        const handleBeforeUnload = (
            event: BeforeUnloadEvent,
        ) => {
            if (!isDirty) {
                return;
            }

            event.preventDefault();
        };

        window.addEventListener(
            "beforeunload",
            handleBeforeUnload,
        );

        return () => {
            window.removeEventListener(
                "beforeunload",
                handleBeforeUnload,
            );
        };
    }, [isDirty]);

    const requestClose = () => {
        if (processing) {
            return;
        }

        if (isDirty) {
            setDiscardDialogOpen(true);
            return;
        }

        onClose();
    };

    /**
     * ESC should behave like Back / Close.
     *
     * It must NOT silently discard changes.
     */
    useEffect(() => {
        const handleKeyDown = (
            event: KeyboardEvent,
        ) => {
            if (event.key !== "Escape") {
                return;
            }

            if (discardDialogOpen) {
                return;
            }

            event.preventDefault();

            requestClose();
        };

        window.addEventListener(
            "keydown",
            handleKeyDown,
        );

        return () => {
            window.removeEventListener(
                "keydown",
                handleKeyDown,
            );
        };
    }, [
        isDirty,
        discardDialogOpen,
    ]);

    if (!design) {
        return (
            <div className="fixed inset-0 z-[100] flex items-center justify-center bg-background p-6">
                <div className="max-w-lg rounded-xl border border-destructive/30 bg-destructive/5 p-6">
                    <h2 className="font-semibold text-destructive">
                        Design not found
                    </h2>

                    <p className="mt-2 text-sm text-muted-foreground">
                        Could not find{" "}
                        {section.type} /{" "}
                        {section.variant}.
                    </p>

                    <Button
                        className="mt-5"
                        onPress={onClose}
                    >
                        Back
                    </Button>
                </div>
            </div>
        );
    }

    const PreviewComponent =
        design.component;

    

    const discardChanges = () => {
        reset();

        setDiscardDialogOpen(false);

        onClose();
    };

    const saveSection = () => {
        put(
            `/admin/pages/${pageId}/sections/${section.id}`,
            {
                preserveScroll: true,

                onSuccess: () => {
                    onClose();
                },

                onError: () => {
                    toast.error(
                        "Please check the section settings.",
                    );
                },
            },
        );
    };

    const previewWidth =
        previewMode === "mobile"
            ? "max-w-[390px]"
            : previewMode === "tablet"
              ? "max-w-[768px]"
              : "max-w-none";

    return (
        <>
            {/*
                FULL SCREEN WORKSPACE

                There is no backdrop and no outside click
                handler, so this cannot accidentally close.
            */}
            <div className="fixed inset-0 z-[100] flex flex-col bg-background">
                {/* Header */}
                <header className="flex shrink-0 items-center justify-between gap-4 border-b bg-background px-4 py-3">
                    <div className="flex min-w-0 items-center gap-3">
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Back"
                            onPress={requestClose}
                            isDisabled={
                                processing
                            }
                        >
                            <ArrowLeft />
                        </Button>

                        <div className="min-w-0">
                            <div className="truncate text-sm font-semibold">
                                {data.name}
                            </div>

                            <div className="truncate text-xs text-muted-foreground">
                                {group?.label} ·{" "}
                                {design.label}
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        {/* Responsive preview */}
                        <div className="hidden items-center rounded-lg border bg-muted/20 p-1 md:flex">
                            <Button
                                variant={
                                    previewMode ===
                                    "desktop"
                                        ? "secondary"
                                        : "ghost"
                                }
                                size="icon-sm"
                                aria-label="Desktop preview"
                                onPress={() =>
                                    setPreviewMode(
                                        "desktop",
                                    )
                                }
                            >
                                <Monitor />
                            </Button>

                            <Button
                                variant={
                                    previewMode ===
                                    "tablet"
                                        ? "secondary"
                                        : "ghost"
                                }
                                size="icon-sm"
                                aria-label="Tablet preview"
                                onPress={() =>
                                    setPreviewMode(
                                        "tablet",
                                    )
                                }
                            >
                                <Tablet />
                            </Button>

                            <Button
                                variant={
                                    previewMode ===
                                    "mobile"
                                        ? "secondary"
                                        : "ghost"
                                }
                                size="icon-sm"
                                aria-label="Mobile preview"
                                onPress={() =>
                                    setPreviewMode(
                                        "mobile",
                                    )
                                }
                            >
                                <Smartphone />
                            </Button>
                        </div>

                        {isDirty && (
                            <span className="hidden text-xs font-medium text-amber-600 sm:block">
                                Unsaved changes
                            </span>
                        )}

                        <Button
                            onPress={saveSection}
                            isDisabled={
                                processing ||
                                !isDirty
                            }
                        >
                            <Save />

                            {processing
                                ? "Saving..."
                                : "Save"}
                        </Button>

                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Close editor"
                            onPress={requestClose}
                            isDisabled={
                                processing
                            }
                        >
                            <X />
                        </Button>
                    </div>
                </header>

                {/* Workspace */}
                <div className="grid min-h-0 flex-1 lg:grid-cols-12">
                    {/* LEFT — Editor */}
                    <aside className="min-h-0 overflow-y-auto border-r bg-background lg:col-span-4 xl:col-span-3">
                        <div className="p-5">
                            <SectionEditor
                                design={design}
                                data={data}
                                setData={setData}
                                errors={errors}
                                processing={
                                    processing
                                }
                                onSave={
                                    saveSection
                                }
                                onCancel={
                                    requestClose
                                }
                            />
                        </div>
                    </aside>

                    {/* RIGHT — Live Preview */}
                    <main className="min-h-0 overflow-auto bg-muted/30 lg:col-span-8 xl:col-span-9">
                        <div className="min-h-full p-4 md:p-8">
                            <div
                                className={`mx-auto overflow-hidden bg-background shadow-sm transition-[max-width] duration-200 ${previewWidth}`}
                            >
                                <PreviewComponent
                                    settings={
                                        data.settings
                                    }
                                    preview
                                />
                            </div>
                        </div>
                    </main>
                </div>
            </div>

            {/* Unsaved changes protection */}
            <AlertDialogTrigger
                isOpen={
                    discardDialogOpen
                }
                onOpenChange={
                    setDiscardDialogOpen
                }
            >
                <AlertDialog>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Discard unsaved
                            changes?
                        </AlertDialogTitle>

                        <AlertDialogDescription>
                            This section contains
                            changes that have not
                            been saved. If you
                            leave now, those
                            changes will be lost.
                        </AlertDialogDescription>
                    </AlertDialogHeader>

                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            Keep Editing
                        </AlertDialogCancel>

                        <AlertDialogAction
                            variant="destructive"
                            onPress={
                                discardChanges
                            }
                        >
                            Discard Changes
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialog>
            </AlertDialogTrigger>
        </>
    );
}