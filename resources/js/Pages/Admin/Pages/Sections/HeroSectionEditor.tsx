import { useForm } from "@inertiajs/react";
import toast from "react-hot-toast";

import { Button } from "@/Components/ui/button";

type HeroSettings = {
    eyebrow?: string;
    title?: string;
    description?: string;
    button_text?: string;
    button_url?: string;
    background_image?: string;
};

type PageSection = {
    id: number;
    page_id: number;
    type: "hero";
    name: string | null;
    variant: string;
    settings: HeroSettings;
    sort_order: number;
    is_active: boolean;
};

type Props = {
    pageId: number;
    section: PageSection;
    onClose: () => void;
};

export default function HeroSectionEditor({
    pageId,
    section,
    onClose,
}: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: section.name ?? "Hero",
        variant: section.variant ?? "default",
        is_active: section.is_active,

        settings: {
            eyebrow: section.settings?.eyebrow ?? "",
            title: section.settings?.title ?? "",
            description: section.settings?.description ?? "",
            button_text: section.settings?.button_text ?? "",
            button_url: section.settings?.button_url ?? "",
            background_image:
                section.settings?.background_image ?? "",
        },
    });

    const updateSetting = (
        key: keyof HeroSettings,
        value: string,
    ) => {
        setData("settings", {
            ...data.settings,
            [key]: value,
        });
    };

    const submit = () => {
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

    return (
        <div className="space-y-6">
            <div>
                <label className="mb-2 block text-sm font-medium">
                    Section Name
                </label>

                <input
                    type="text"
                    value={data.name}
                    onChange={(e) =>
                        setData("name", e.target.value)
                    }
                    className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />

                {errors.name && (
                    <p className="mt-1 text-sm text-destructive">
                        {errors.name}
                    </p>
                )}
            </div>

            <div>
                <label className="mb-2 block text-sm font-medium">
                    Variant
                </label>

                <select
                    value={data.variant}
                    onChange={(e) =>
                        setData("variant", e.target.value)
                    }
                    className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                >
                    <option value="default">
                        Default
                    </option>
                    <option value="centered">
                        Centered
                    </option>
                    <option value="split">
                        Split
                    </option>
                </select>
            </div>

            <div>
                <label className="mb-2 block text-sm font-medium">
                    Eyebrow
                </label>

                <input
                    type="text"
                    value={data.settings.eyebrow}
                    onChange={(e) =>
                        updateSetting(
                            "eyebrow",
                            e.target.value,
                        )
                    }
                    placeholder="Welcome to our company"
                    className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />
            </div>

            <div>
                <label className="mb-2 block text-sm font-medium">
                    Title
                </label>

                <input
                    type="text"
                    value={data.settings.title}
                    onChange={(e) =>
                        updateSetting(
                            "title",
                            e.target.value,
                        )
                    }
                    placeholder="Building better businesses"
                    className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />
            </div>

            <div>
                <label className="mb-2 block text-sm font-medium">
                    Description
                </label>

                <textarea
                    rows={5}
                    value={data.settings.description}
                    onChange={(e) =>
                        updateSetting(
                            "description",
                            e.target.value,
                        )
                    }
                    className="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <label className="mb-2 block text-sm font-medium">
                        Button Text
                    </label>

                    <input
                        type="text"
                        value={data.settings.button_text}
                        onChange={(e) =>
                            updateSetting(
                                "button_text",
                                e.target.value,
                            )
                        }
                        placeholder="Learn More"
                        className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                    />
                </div>

                <div>
                    <label className="mb-2 block text-sm font-medium">
                        Button URL
                    </label>

                    <input
                        type="text"
                        value={data.settings.button_url}
                        onChange={(e) =>
                            updateSetting(
                                "button_url",
                                e.target.value,
                            )
                        }
                        placeholder="/about"
                        className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                    />
                </div>
            </div>

            <div>
                <label className="mb-2 block text-sm font-medium">
                    Background Image
                </label>

                <input
                    type="text"
                    value={data.settings.background_image}
                    onChange={(e) =>
                        updateSetting(
                            "background_image",
                            e.target.value,
                        )
                    }
                    placeholder="/storage/images/hero.jpg"
                    className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />

                <p className="mt-1 text-xs text-muted-foreground">
                    We will replace this with the Media Library
                    later.
                </p>
            </div>

            <div className="flex items-center gap-3">
                <input
                    id={`section-active-${section.id}`}
                    type="checkbox"
                    checked={data.is_active}
                    onChange={(e) =>
                        setData(
                            "is_active",
                            e.target.checked,
                        )
                    }
                    className="size-4"
                />

                <label
                    htmlFor={`section-active-${section.id}`}
                    className="text-sm font-medium"
                >
                    Show this section
                </label>
            </div>

            <div className="flex justify-end gap-2 border-t pt-5">
                <Button
                    variant="outline"
                    onPress={onClose}
                    isDisabled={processing}
                >
                    Cancel
                </Button>

                <Button
                    onPress={submit}
                    isDisabled={processing}
                >
                    {processing
                        ? "Saving..."
                        : "Save Section"}
                </Button>
            </div>
        </div>
    );
}