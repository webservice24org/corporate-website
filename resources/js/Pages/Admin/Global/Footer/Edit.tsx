import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    useForm,
} from "@inertiajs/react";

import {
    Save,
} from "lucide-react";

import toast from "react-hot-toast";

import {
    Button,
} from "@/Components/ui/button";

import SectionEditor from "@/PageBuilder/components/SectionEditor";

import {
    footerRegistry,
} from "@/PageBuilder/registries/footerRegistry";

import type {
    SectionSettings,
} from "@/PageBuilder/types";

type GlobalFooter = {
    id: number;

    type: "footer";

    variant: string;

    settings:
        | SectionSettings
        | null;

    is_active: boolean;
};

type Props = {
    footer:
        | GlobalFooter
        | null;
};

type FooterForm = {
    variant: string;

    settings: Record<
        string,
        any
    >;

    is_active: boolean;
};

export default function Edit({
    footer,
}: Props) {
    /*
     * Get first Footer design.
     *
     * At the moment we only have footer-01,
     * but this also prepares us for footer-02,
     * footer-03 later.
     */
    const defaultDesign =
        footerRegistry.designs[0];

    /*
     * Current variant.
     */
    const initialVariant =
        footer?.variant ??
        defaultDesign.id;

    /*
     * Get current design definition.
     */
    const initialDesign =
        footerRegistry.designs.find(
            (design) =>
                design.id ===
                initialVariant,
        ) ?? defaultDesign;

    const {
        data,
        setData,
        put,
        processing,
        errors,
    } = useForm<FooterForm>({
        variant:
            initialVariant,

        settings:
            footer?.settings ??
            initialDesign
                .defaultSettings,

        is_active:
            footer?.is_active ??
            true,
    });

    /*
     * Current selected footer design.
     */
    const design =
        footerRegistry.designs.find(
            (item) =>
                item.id ===
                data.variant,
        ) ?? defaultDesign;

    /*
     * Change Footer design.
     *
     * When switching to another future
     * Footer variant, use that variant's
     * default settings.
     */
    const changeVariant = (
        variant: string,
    ) => {
        const nextDesign =
            footerRegistry.designs.find(
                (item) =>
                    item.id ===
                    variant,
            );

        if (!nextDesign) {
            return;
        }

        setData(
            "variant",
            variant,
        );

        setData(
            "settings",
            nextDesign.defaultSettings,
        );
    };

    /*
     * Update one Footer setting.
     */
    const updateSetting = (
        key: string,
        value: unknown,
    ) => {
        setData(
            "settings",
            {
                ...data.settings,

                [key]:
                    value,
            },
        );
    };

    /*
     * Save global Footer.
     */
    const submit = () => {
        put(
            "/admin/global/footer",
            {
                preserveScroll: true,


                onError: () => {
                    toast.error(
                        "Please check the footer settings.",
                    );
                },
            },
        );
    };

    const PreviewComponent =
        design.component;

    return (
        <AdminLayout>
            <Head title="Global Footer" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Global Footer
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Configure the
                            footer once and
                            use it across
                            every frontend
                            page.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <label className="flex cursor-pointer items-center gap-2 rounded-lg border bg-background px-4 py-2.5">
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
                                Footer
                                Active
                            </span>
                        </label>

                        <Button
                            onPress={
                                submit
                            }
                            isDisabled={
                                processing
                            }
                        >
                            <Save />

                            {processing
                                ? "Saving..."
                                : "Save Footer"}
                        </Button>
                    </div>
                </div>

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

                {/* Main Editor */}
                <div className="grid min-h-[720px] overflow-hidden rounded-2xl border bg-background shadow-sm xl:grid-cols-[400px_minmax(0,1fr)]">
                    {/* LEFT EDITOR */}
                    <aside className="border-b bg-background xl:border-b-0 xl:border-r">
                        <div className="border-b p-4">
                            <div>
                                <label className="mb-2 block text-sm font-medium">
                                    Footer
                                    Design
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
                                    {footerRegistry.designs.map(
                                        (
                                            item,
                                        ) => (
                                            <option
                                                key={
                                                    item.id
                                                }
                                                value={
                                                    item.id
                                                }
                                            >
                                                {
                                                    item.label
                                                }
                                            </option>
                                        ),
                                    )}
                                </select>

                                <p className="mt-2 text-xs text-muted-foreground">
                                    {
                                        design.description
                                    }
                                </p>
                            </div>
                        </div>

                        <div className="max-h-[calc(100vh-230px)] overflow-y-auto p-4">
                            <SectionEditor
                                design={
                                    design
                                }
                                data={{
                                    name:
                                        "Global Footer",

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
                                     * SectionEditor
                                     * edits the same
                                     * structure used
                                     * by Page Builder.
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

                    {/* RIGHT LIVE PREVIEW */}
                    <main className="min-w-0 bg-muted/30">
                        <div className="flex items-center justify-between border-b bg-background px-5 py-3">
                            <div>
                                <div className="text-sm font-semibold">
                                    Live
                                    Preview
                                </div>

                                <div className="text-xs text-muted-foreground">
                                    {
                                        design.label
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
                                    : "Disabled"}
                            </span>
                        </div>

                        <div className="h-[calc(100vh-230px)] min-h-[650px] overflow-auto">
                            {data.is_active ? (
                                <PreviewComponent
                                    settings={
                                        data.settings
                                    }
                                    preview
                                />
                            ) : (
                                <div className="flex min-h-[650px] items-center justify-center p-10">
                                    <div className="max-w-sm text-center">
                                        <h3 className="font-semibold">
                                            Footer
                                            disabled
                                        </h3>

                                        <p className="mt-2 text-sm text-muted-foreground">
                                            Enable the
                                            Footer to
                                            preview and
                                            display it on
                                            frontend
                                            pages.
                                        </p>
                                    </div>
                                </div>
                            )}
                        </div>
                    </main>
                </div>
            </div>
        </AdminLayout>
    );
}