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
    floatingContactRegistry,
} from "@/PageBuilder/registries/floatingContactRegistry";

import type {
    SectionSettings,
} from "@/PageBuilder/types";

type GlobalSection = {
    id: number;

    type:
        "floating-contact";

    variant:
        string;

    settings:
        | SectionSettings
        | null;

    is_active:
        boolean;
};

type Props = {
    section:
        | GlobalSection
        | null;
};

type FormData = {
    variant: string;

    settings: Record<
        string,
        any
    >;

    is_active:
        boolean;
};

export default function Edit({
    section,
}: Props) {
    const defaultDesign =
        floatingContactRegistry
            .designs[0];

    const initialVariant =
        section?.variant ??
        defaultDesign.id;

    const initialDesign =
        floatingContactRegistry.designs.find(
            (item) =>
                item.id ===
                initialVariant,
        ) ?? defaultDesign;

    const {
        data,
        setData,
        put,
        processing,
    } = useForm<FormData>({
        variant:
            initialVariant,

        settings:
            section?.settings ??
            initialDesign
                .defaultSettings,

        is_active:
            section?.is_active ??
            true,
    });

    const design =
        floatingContactRegistry.designs.find(
            (item) =>
                item.id ===
                data.variant,
        ) ?? defaultDesign;

    const PreviewComponent =
        design.component;

    const submit = () => {
        put(
            "/admin/global/floating-contact",
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
            <Head title="Floating Contact" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Floating Contact
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Configure global
                            floating phone and
                            WhatsApp buttons.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <label className="flex items-center gap-2 rounded-lg border px-4 py-2.5">
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
                                className="size-4"
                            />

                            <span className="text-sm font-medium">
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
                                : "Save"}
                        </Button>
                    </div>
                </div>

                <div className="grid min-h-[650px] overflow-hidden rounded-2xl border xl:grid-cols-[400px_minmax(0,1fr)]">
                    <aside className="border-r bg-background p-4">
                        <SectionEditor
                            design={
                                design
                            }
                            data={{
                                name:
                                    "Floating Contact",

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
                                if (
                                    key ===
                                    "settings"
                                ) {
                                    setData(
                                        "settings",
                                        value as SectionSettings,
                                    );
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
                    </aside>

                    <main className="relative min-h-[650px] bg-muted/30">
                        <div className="border-b bg-background px-5 py-3">
                            <div className="font-semibold">
                                Live Preview
                            </div>

                            <div className="text-xs text-muted-foreground">
                                {
                                    data.is_active
                                        ? "Active"
                                        : "Inactive"
                                }
                            </div>
                        </div>

                        <div className="relative min-h-[590px]">
                            {data.is_active ? (
                                <PreviewComponent
                                    settings={
                                        data.settings
                                    }
                                    preview
                                />
                            ) : (
                                <div className="flex min-h-[590px] items-center justify-center">
                                    <p className="text-sm text-muted-foreground">
                                        Floating
                                        contact is
                                        disabled.
                                    </p>
                                </div>
                            )}
                        </div>
                    </main>
                </div>
            </div>
        </AdminLayout>
    );
}