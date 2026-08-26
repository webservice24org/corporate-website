import { useState } from "react";

import { Button } from "@/Components/ui/button";
import FieldPanel from "@/PageBuilder/components/FieldPanel";

import ImageField from "./ImageField";

import type {
    SectionDesign,
    SectionField,
} from "../types";
import {
    Plus,
    Trash2,
} from "lucide-react";
import IconPicker from "./IconPicker";

export type EditorForm = {
    name: string;

    variant: string;

    settings: Record<
        string,
        any
    >;

    is_active: boolean;
};

type EditorSetData = <
    K extends keyof EditorForm,
>(
    key: K,
    value: EditorForm[K],
) => void;

type Props = {
    design: SectionDesign;

    data: EditorForm;

    setData: EditorSetData;

    errors: Record<
        string,
        string | undefined
    >;

    processing: boolean;

    onSave?: () => void;

    onCancel?: () => void;
};

const panelLabels: Record<string, string> = {
    content: "Content",
    background: "Background",
    typography: "Typography",
    alignment: "Alignment",
    spacing: "Spacing",
    layout: "Layout",
    responsive: "Responsive",
    developer: "Developer",
};

export default function SectionEditor({
    design,
    data,
    setData,
    errors,
    processing,
    onSave,
    onCancel,
}: Props) {
    const [activeTab, setActiveTab] =
        useState<"content" | "style" | "advanced">(
            "content",
        );

    const availableGroups = Array.from(
        new Set(
            design.fields.map(
                (field) => field.group ?? "content",
            ),
        ),
    );

    const visibleFields = design.fields.filter(
        (field) =>
            (field.group ?? "content") === activeTab,
    );

    const fieldsByPanel = visibleFields.reduce<
        Record<string, SectionField[]>
    >((groups, field) => {
        const panel =
            field.panel ??
            (activeTab === "content"
                ? "content"
                : activeTab);

        if (!groups[panel]) {
            groups[panel] = [];
        }

        groups[panel].push(field);

        return groups;
    }, {});

    const updateSetting = (
        key: string,
        value: unknown,
    ) => {
        setData("settings", {
            ...data.settings,
            [key]: value,
        });
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
                    Design
                </label>

                <div className="rounded-lg border bg-muted/40 px-3 py-2">
                    <div className="text-sm font-medium">
                        {design.label}
                    </div>

                    <div className="mt-0.5 text-xs text-muted-foreground">
                        {data.variant}
                    </div>
                </div>
            </div>

            {availableGroups.length > 0 && (
                <div className="border-t pt-5">
                    <div
                        className="grid gap-1 rounded-lg bg-muted p-1"
                        style={{
                            gridTemplateColumns: `repeat(${availableGroups.length}, minmax(0, 1fr))`,
                        }}
                    >
                        {availableGroups.includes("content") && (
                            <button
                                type="button"
                                onClick={() =>
                                    setActiveTab("content")
                                }
                                className={`rounded-md px-3 py-2 text-sm font-medium transition ${
                                    activeTab === "content"
                                        ? "bg-background text-foreground shadow-sm"
                                        : "text-muted-foreground hover:text-foreground"
                                }`}
                            >
                                Content
                            </button>
                        )}

                        {availableGroups.includes("style") && (
                            <button
                                type="button"
                                onClick={() =>
                                    setActiveTab("style")
                                }
                                className={`rounded-md px-3 py-2 text-sm font-medium transition ${
                                    activeTab === "style"
                                        ? "bg-background text-foreground shadow-sm"
                                        : "text-muted-foreground hover:text-foreground"
                                }`}
                            >
                                Style
                            </button>
                        )}

                        {availableGroups.includes("advanced") && (
                            <button
                                type="button"
                                onClick={() =>
                                    setActiveTab("advanced")
                                }
                                className={`rounded-md px-3 py-2 text-sm font-medium transition ${
                                    activeTab === "advanced"
                                        ? "bg-background text-foreground shadow-sm"
                                        : "text-muted-foreground hover:text-foreground"
                                }`}
                            >
                                Advanced
                            </button>
                        )}
                    </div>
                </div>
            )}

            <div>
                {activeTab === "content" ? (
                    <div className="space-y-5">
                        {visibleFields.map((field) => (
                            <FieldRenderer
                                key={field.key}
                                field={field}
                                value={data.settings[field.key]}
                                values={data.settings}
                                onChange={(value) =>
                                    updateSetting(field.key, value)
                                }
                            />
                        ))}
                    </div>
                ) : (
                    <div className="space-y-3">
                        {Object.entries(fieldsByPanel).map(
                            ([panel, fields], panelIndex) => (
                                <FieldPanel
                                    key={panel}
                                    title={
                                        panelLabels[panel] ?? panel
                                    }
                                    defaultOpen={panelIndex === 0}
                                >
                                    {fields.map((field) => (
                                        <FieldRenderer
                                            key={field.key}
                                            field={field}
                                            value={
                                                data.settings[field.key]
                                            }
                                            values={data.settings}
                                            onChange={(value) =>
                                                updateSetting(
                                                    field.key,
                                                    value,
                                                )
                                            }
                                        />
                                    ))}
                                </FieldPanel>
                            ),
                        )}
                    </div>
                )}
            </div>

            <div className="border-t pt-5">
                <label className="flex cursor-pointer items-center gap-3">
                    <input
                        type="checkbox"
                        checked={data.is_active}
                        onChange={(e) =>
                            setData(
                                "is_active",
                                e.target.checked,
                            )
                        }
                        className="size-4 rounded border-input"
                    />

                    <span className="text-sm font-medium">
                        Show this section
                    </span>
                </label>
            </div>

            <div className="flex justify-end gap-2 border-t pt-5">
                {onCancel && (
                    <Button
                        variant="outline"
                        onPress={onCancel}
                        isDisabled={processing}
                    >
                        Cancel
                    </Button>
                )}

                {onSave && (
                    <Button
                        onPress={onSave}
                        isDisabled={processing}
                    >
                        Save
                    </Button>
                )}
            </div>
        </div>
    );
}

type FieldRendererProps = {
    field: SectionField;

    value: unknown;

    values: Record<string, unknown>;

    onChange: (value: unknown) => void;
};

function FieldRenderer({
    field,
    value,
    values,
    onChange,
}: FieldRendererProps) {
    if (field.visibleWhen) {
        const dependentValue =
            values[field.visibleWhen.field];

        if (
            dependentValue !==
            field.visibleWhen.equals
        ) {
            return null;
        }
    }

    if (field.type === "icon") {
        return (
            <div className="space-y-2">
                <label className="text-sm font-medium">
                    {field.label}
                </label>

                <IconPicker
                    value={
                        typeof value ===
                        "string"
                            ? value
                            : ""
                    }
                    onChange={
                        onChange
                    }
                />
            </div>
        );
    }


        if (field.type === "repeater") {
        const items = Array.isArray(value)
            ? (value as Record<string, unknown>[])
            : [];

        const minItems =
            field.min ?? 0;

        const maxItems =
            field.max ?? Infinity;

        const canAdd =
            items.length < maxItems;

        const canRemove =
            items.length > minItems;

        const updateItem = (
            index: number,
            key: string,
            itemValue: unknown,
        ) => {
            const nextItems = items.map(
                (item, itemIndex) =>
                    itemIndex === index
                        ? {
                            ...item,
                            [key]: itemValue,
                        }
                        : item,
            );

            onChange(nextItems);
        };

        const addItem = () => {
            if (!canAdd) {
                return;
            }

            const newItem = {
                ...(field.defaultItem ?? {}),
            };

            onChange([
                ...items,
                newItem,
            ]);
        };

        const removeItem = (
            index: number,
        ) => {
            if (!canRemove) {
                return;
            }

            const nextItems =
                items.filter(
                    (_, itemIndex) =>
                        itemIndex !== index,
                );

            onChange(nextItems);
        };

        const itemLabel =
            field.itemLabel ??
            "Item";

        return (
            <div className="space-y-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h4 className="text-sm font-semibold">
                            {field.label}
                        </h4>

                        <p className="mt-1 text-xs text-muted-foreground">
                            {items.length}{" "}
                            {items.length === 1
                                ? itemLabel
                                : `${itemLabel}s`}
                        </p>
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onPress={addItem}
                        isDisabled={!canAdd}
                    >
                        <Plus />
                        Add
                    </Button>
                </div>

                {items.length === 0 && (
                    <div className="rounded-lg border border-dashed p-6 text-center">
                        <p className="text-sm text-muted-foreground">
                            No {itemLabel.toLowerCase()}s
                            added yet.
                        </p>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="mt-3"
                            onPress={addItem}
                        >
                            <Plus />
                            Add {itemLabel}
                        </Button>
                    </div>
                )}

                {items.map(
                    (item, index) => (
                        <details
                            key={index}
                            open={index === 0}
                            className="overflow-hidden rounded-lg border bg-muted/20"
                        >
                            <summary className="cursor-pointer select-none">
                                <div className="flex items-center justify-between gap-3 px-4 py-3">
                                    <div className="min-w-0">
                                        <span className="text-sm font-medium">
                                            {itemLabel}{" "}
                                            {index + 1}
                                        </span>

                                        {typeof item.title ===
                                            "string" &&
                                            item.title && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    —{" "}
                                                    {
                                                        item.title
                                                    }
                                                </span>
                                            )}
                                    </div>

                                    <button
                                        type="button"
                                        aria-label={`Remove ${itemLabel} ${
                                            index + 1
                                        }`}
                                        disabled={
                                            !canRemove
                                        }
                                        onClick={(
                                            event,
                                        ) => {
                                            /*
                                            * Prevent clicking delete
                                            * from toggling <details>.
                                            */
                                            event.preventDefault();
                                            event.stopPropagation();

                                            removeItem(
                                                index,
                                            );
                                        }}
                                        className="flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive disabled:pointer-events-none disabled:opacity-30"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            </summary>

                            <div className="space-y-4 border-t p-4">
                                {field.fields.map(
                                    (
                                        childField,
                                    ) => (
                                        <FieldRenderer
                                            key={
                                                childField.key
                                            }
                                            field={
                                                childField
                                            }
                                            value={
                                                item[
                                                    childField
                                                        .key
                                                ]
                                            }
                                            values={
                                                item
                                            }
                                            onChange={(
                                                childValue,
                                            ) =>
                                                updateItem(
                                                    index,
                                                    childField.key,
                                                    childValue,
                                                )
                                            }
                                        />
                                    ),
                                )}
                            </div>
                        </details>
                    ),
                )}

                {items.length > 0 &&
                    canAdd && (
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full"
                            onPress={addItem}
                        >
                            <Plus />
                            Add {itemLabel}
                        </Button>
                    )}

                {field.max &&
                    items.length >=
                        field.max && (
                        <p className="text-center text-xs text-muted-foreground">
                            Maximum{" "}
                            {field.max}{" "}
                            {itemLabel.toLowerCase()}
                            s reached.
                        </p>
                    )}
            </div>
        );
    }

    if (field.type === "textarea") {
        return (
            <div>
                <label className="mb-2 block text-sm font-medium">
                    {field.label}
                </label>

                <div>
                    <textarea
                        rows={field.rows ?? 4}
                        value={String(value ?? "")}
                        placeholder={field.placeholder}
                        maxLength={field.maxLength}
                        onChange={(e) =>
                            onChange(e.target.value)
                        }
                        className="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                    />

                    {field.maxLength && (
                        <div className="mt-1 text-right text-xs text-muted-foreground">
                            {String(value ?? "").length}
                            {" / "}
                            {field.maxLength}
                        </div>
                    )}
                </div>
            </div>
        );
    }

    if (field.type === "select") {
        return (
            <div>
                <label className="mb-2 block text-sm font-medium">
                    {field.label}
                </label>

                <select
                    value={String(value ?? "")}
                    onChange={(e) =>
                        onChange(e.target.value)
                    }
                    className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring"
                >
                    {field.options.map(
                        (option) => (
                            <option
                                key={option.value}
                                value={option.value}
                            >
                                {option.label}
                            </option>
                        ),
                    )}
                </select>
            </div>
        );
    }

    if (field.type === "checkbox") {
        return (
            <label className="flex items-center gap-3 cursor-pointer">
                <input
                    type="checkbox"
                    checked={Boolean(value)}
                    onChange={(e) =>
                        onChange(
                            e.target.checked,
                        )
                    }
                    className="size-4 rounded border-input"
                />

                <span className="text-sm font-medium">
                    {field.label}
                </span>
            </label>
        );
    }

    if (field.type === "image") {
        return (
            <ImageField
                label={field.label}
                value={value}
                onChange={(imageUrl) =>
                    onChange(imageUrl)
                }
            />
        );
    }

    if (
        field.type === "number" ||
        field.type === "color"
    ) {
        return (
            <div>
                <label className="mb-2 block text-sm font-medium">
                    {field.label}
                </label>

                <input
                    type={
                        field.type === "number"
                            ? "number"
                            : "color"
                    }
                    value={
                        field.type === "number"
                            ? String(value ?? "")
                            : String(
                                  value ??
                                      "#000000",
                              )
                    }
                    onChange={(e) =>
                        onChange(
                            field.type === "number"
                                ? Number(
                                      e.target.value,
                                  )
                                : e.target.value,
                        )
                    }
                    className={
                        field.type === "color"
                            ? "h-10 w-full cursor-pointer rounded-lg border border-input bg-background p-1"
                            : "h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring"
                    }
                />
            </div>
        );
    }

    return (
        <div>
            <label className="mb-2 block text-sm font-medium">
                {field.label}
            </label>

            <input
                type="text"
                value={String(value ?? "")}
                placeholder={field.placeholder}
                maxLength={field.maxLength}
                onChange={(e) =>
                    onChange(e.target.value)
                }
                className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
            />
        </div>
    );
}