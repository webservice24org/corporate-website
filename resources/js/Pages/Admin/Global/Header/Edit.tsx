import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    useForm,
} from "@inertiajs/react";

import {
    useMemo,
} from "react";

import type {
    FormEvent,
} from "react";

import {
    Save,
} from "lucide-react";

import {
    Button,
} from "@/Components/ui/button";

import {
    headerRegistry,
} from "@/PageBuilder/registries/headerRegistry";

import HeaderRenderer from "@/PageBuilder/sections/Global/Header/HeaderRenderer";
import toast from "react-hot-toast";

import SectionEditor from "@/PageBuilder/components/SectionEditor";

import type {
    SectionSettings,
} from "@/PageBuilder/types";

type HeaderVariant =
    | "header-01"
    | "header-02"
    | "header-03"
    | "header-04"
    | "header-05";

type GlobalHeader = {
    id: number;

    type: "header";

    variant: HeaderVariant;

    settings:
        | SectionSettings
        | null;

    is_active: boolean;
};

type Props = {
    header:
        | GlobalHeader
        | null;
};

type HeaderForm = {
    variant: HeaderVariant;

    settings: Record<
        string,
        any
    >;

    is_active: boolean;
};

export default function Edit({
    header,
}: Props) {
    /*
     * First available design.
     */
    const firstDesign =
        headerRegistry.designs[0];

    /*
     * Initial variant.
     */
    const initialVariant =
        (header?.variant ??
            firstDesign.id) as HeaderVariant;

    /*
     * Initial design.
     */
    const initialDesign =
        headerRegistry.designs.find(
            (design) =>
                design.id ===
                initialVariant,
        ) ?? firstDesign;

    /*
     * Form
     */
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } = useForm<HeaderForm>({
        variant:
            initialVariant,

        settings: {
            ...initialDesign
                .defaultSettings,

            ...(header?.settings ??
                {}),
        },

        is_active:
            header?.is_active ??
            true,
    });

    /*
     * Currently selected design.
     */
    const selectedDesign =
        useMemo(() => {
            return (
                headerRegistry.designs.find(
                    (design) =>
                        design.id ===
                        data.variant,
                ) ??
                firstDesign
            );
        }, [
            data.variant,
            firstDesign,
        ]);

    /*
     * Change header design.
     *
     * Existing common settings are
     * preserved while variant-specific
     * defaults are added.
     */
    const changeVariant = (
        variant: string,
    ) => {
        const nextDesign =
            headerRegistry.designs.find(
                (design) =>
                    design.id ===
                    variant,
            );

        if (!nextDesign) {
            return;
        }

        setData(
            "variant",
            variant as HeaderVariant,
        );

        setData(
            "settings",
            {
                ...nextDesign
                    .defaultSettings,

                ...data.settings,
            },
        );
    };

    /*
     * Save to global_sections.
     *
     * Success flash message comes
     * from Laravel controller.
     */
    const submit = (
        event: FormEvent,
    ) => {
        event.preventDefault();

        put(
            "/admin/global/header",
            {
                preserveScroll: true,

                onError: () => {
                    toast.error(
                        "Could not save floating contact settings.",
                    );
                },
                    
            },
        );
    };

    return (
        <AdminLayout>
            <Head title="Global Header" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Global Header
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Configure the
                            website header once
                            and use it across all
                            frontend pages.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        {/* Active */}
                        <label className="flex cursor-pointer items-center gap-3 rounded-lg border bg-background px-4 py-2.5">
                            <input
                                type="checkbox"
                                checked={
                                    data.is_active
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setData(
                                        "is_active",
                                        event
                                            .target
                                            .checked,
                                    )
                                }
                                className="size-4 rounded border-input"
                            />

                            <span className="text-sm font-medium">
                                Header Active
                            </span>
                        </label>

                        <Button
                            type="submit"
                            form="global-header-form"
                            isDisabled={
                                processing
                            }
                        >
                            <Save />

                            {processing
                                ? "Saving..."
                                : "Save Header"}
                        </Button>
                    </div>
                </div>

                {/* Validation */}
                {errors.variant && (
                    <div className="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                        {
                            errors.variant
                        }
                    </div>
                )}

                {errors.settings && (
                    <div className="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                        {
                            errors.settings
                        }
                    </div>
                )}

                <form
                    id="global-header-form"
                    onSubmit={submit}
                >
                    <div className="grid min-h-[760px] overflow-hidden rounded-2xl border bg-background shadow-sm xl:grid-cols-[420px_minmax(0,1fr)]">
                        {/* LEFT EDITOR */}
                        <aside className="border-b bg-background xl:border-b-0 xl:border-r">
                            {/* Design selector */}
                            <div className="border-b p-4">
                                <label className="mb-2 block text-sm font-medium">
                                    Header Design
                                </label>

                                <select
                                    value={
                                        data.variant
                                    }
                                    onChange={(
                                        event,
                                    ) =>
                                        changeVariant(
                                            event
                                                .target
                                                .value,
                                        )
                                    }
                                    className="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none transition focus:border-ring focus:ring-3 focus:ring-ring/20"
                                >
                                    {headerRegistry.designs.map(
                                        (
                                            design,
                                        ) => (
                                            <option
                                                key={
                                                    design.id
                                                }
                                                value={
                                                    design.id
                                                }
                                            >
                                                {
                                                    design.label
                                                }
                                            </option>
                                        ),
                                    )}
                                </select>

                                <p className="mt-2 text-xs leading-relaxed text-muted-foreground">
                                    {
                                        selectedDesign.description
                                    }
                                </p>
                            </div>

                            {/* Existing Page Builder Editor */}
                            <div className="max-h-[calc(100vh-230px)] overflow-y-auto p-4">
                                <SectionEditor
                                    design={
                                        selectedDesign
                                    }

                                    data={{
                                        name:
                                            "Global Header",

                                        variant:
                                            data.variant,

                                        settings:
                                            data.settings,

                                        is_active:
                                            data.is_active,
                                    }}

                                    setData={(
                                        key,
                                        value,
                                    ) => {
                                        /*
                                         * SectionEditor updates
                                         * the entire settings
                                         * object.
                                         */
                                        if (
                                            key ===
                                            "settings"
                                        ) {
                                            setData(
                                                "settings",
                                                value as SectionSettings,
                                            );

                                            return;
                                        }

                                        /*
                                         * Variant change.
                                         */
                                        if (
                                            key ===
                                            "variant"
                                        ) {
                                            changeVariant(
                                                String(
                                                    value,
                                                ),
                                            );

                                            return;
                                        }

                                        /*
                                         * Active / inactive.
                                         */
                                        if (
                                            key ===
                                            "is_active"
                                        ) {
                                            setData(
                                                "is_active",
                                                Boolean(
                                                    value,
                                                ),
                                            );
                                        }
                                    }}

                                    errors={{}}

                                    processing={
                                        processing
                                    }
                                />
                            </div>
                        </aside>

                        {/* RIGHT PREVIEW */}
                        <main className="min-w-0 bg-muted/30">
                            {/* Preview toolbar */}
                            <div className="flex items-center justify-between border-b bg-background px-5 py-3">
                                <div>
                                    <div className="text-sm font-semibold">
                                        Live Preview
                                    </div>

                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                        {
                                            selectedDesign.label
                                        }
                                    </div>
                                </div>

                                <span
                                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                        data.is_active
                                            ? "bg-emerald-50 text-emerald-700"
                                            : "bg-slate-100 text-slate-500"
                                    }`}
                                >
                                    {data.is_active
                                        ? "Active"
                                        : "Inactive"}
                                </span>
                            </div>

                            {/* Preview body */}
                            <div className="h-[calc(100vh-230px)] min-h-[700px] overflow-auto bg-white">
                                {data.is_active ? (
                                    <>
                                        <HeaderRenderer
                                            variant={
                                                data.variant
                                            }
                                            settings={
                                                data.settings
                                            }
                                            preview
                                        />

                                        <PreviewContent />
                                    </>
                                ) : (
                                    <div className="flex min-h-[700px] items-center justify-center p-10">
                                        <div className="max-w-sm text-center">
                                            <h3 className="font-semibold">
                                                Header
                                                disabled
                                            </h3>

                                            <p className="mt-2 text-sm text-muted-foreground">
                                                Enable the
                                                global
                                                header to
                                                preview
                                                and show it
                                                on
                                                frontend
                                                pages.
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </main>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}

/*
 * Fake page content underneath
 * the Header preview.
 */
function PreviewContent() {
    return (
        <div className="p-8">
            <div className="rounded-2xl bg-slate-100 p-8">
                <div className="h-4 w-32 rounded bg-slate-300" />

                <div className="mt-5 h-10 max-w-xl rounded bg-slate-300" />

                <div className="mt-4 h-4 max-w-2xl rounded bg-slate-200" />

                <div className="mt-2 h-4 max-w-lg rounded bg-slate-200" />

                <div className="mt-8 grid gap-4 sm:grid-cols-3">
                    <div className="h-32 rounded-xl bg-slate-200" />

                    <div className="h-32 rounded-xl bg-slate-200" />

                    <div className="h-32 rounded-xl bg-slate-200" />
                </div>
            </div>
        </div>
    );
}