import {
    useMemo,
    useState,
} from "react";

import {
    Check,
    Search,
    X,
} from "lucide-react";

import {
    builderIcons,
    getBuilderIcon,
} from "../utils/icons";

type Props = {
    value?: string;

    onChange: (
        value: string,
    ) => void;
};

export default function IconPicker({
    value = "",
    onChange,
}: Props) {
    const [open, setOpen] =
        useState(false);

    const [search, setSearch] =
        useState("");

    const SelectedIcon =
        getBuilderIcon(value);

    const filteredIcons =
        useMemo(() => {
            const query =
                search
                    .trim()
                    .toLowerCase();

            if (!query) {
                return builderIcons;
            }

            return builderIcons.filter(
                (item) =>
                    item.label
                        .toLowerCase()
                        .includes(
                            query,
                        ) ||
                    item.name
                        .toLowerCase()
                        .includes(
                            query,
                        ),
            );
        }, [search]);

    const selectIcon = (
        iconName: string,
    ) => {
        onChange(iconName);

        setOpen(false);
        setSearch("");
    };

    return (
        <div className="relative">
            <button
                type="button"
                onClick={() =>
                    setOpen(
                        (current) =>
                            !current,
                    )
                }
                className="flex w-full items-center justify-between gap-3 rounded-lg border bg-background px-3 py-2 text-left transition hover:bg-muted/50"
            >
                <span className="flex min-w-0 items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-muted/40">
                        <SelectedIcon className="size-5" />
                    </span>

                    <span className="min-w-0">
                        <span className="block text-sm font-medium">
                            {value ||
                                "Choose Icon"}
                        </span>

                        <span className="block text-xs text-muted-foreground">
                            Click to browse
                            icons
                        </span>
                    </span>
                </span>

                <Search className="size-4 shrink-0 text-muted-foreground" />
            </button>

            {open && (
                <div className="absolute left-0 top-full z-50 mt-2 w-full min-w-[320px] overflow-hidden rounded-xl border bg-background shadow-xl">
                    <div className="flex items-center gap-2 border-b p-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" />

                        <input
                            type="text"
                            autoFocus
                            value={search}
                            onChange={(
                                event,
                            ) =>
                                setSearch(
                                    event
                                        .target
                                        .value,
                                )
                            }
                            placeholder="Search icons..."
                            className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        />

                        <button
                            type="button"
                            onClick={() => {
                                setOpen(
                                    false,
                                );

                                setSearch(
                                    "",
                                );
                            }}
                            className="flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                            aria-label="Close icon picker"
                        >
                            <X className="size-4" />
                        </button>
                    </div>

                    <div className="icon-picker-scroll max-h-[400px] overflow-y-auto overscroll-contain p-3">
                        {filteredIcons.length >
                        0 ? (
                            <div className="grid grid-cols-5 gap-2">
                                {filteredIcons.map(
                                    (
                                        item,
                                    ) => {
                                        const Icon =
                                            item.icon;

                                        const selected =
                                            item.name ===
                                            value;

                                        return (
                                            <button
                                                key={
                                                    item.name
                                                }
                                                type="button"
                                                title={
                                                    item.label
                                                }
                                                onClick={() =>
                                                    selectIcon(
                                                        item.name,
                                                    )
                                                }
                                                className={`relative flex aspect-square items-center justify-center rounded-lg border transition ${
                                                    selected
                                                        ? "border-primary bg-primary/10 text-primary"
                                                        : "border-transparent bg-muted/40 text-muted-foreground hover:border-border hover:bg-muted hover:text-foreground"
                                                }`}
                                            >
                                                <Icon className="size-5" />

                                                {selected && (
                                                    <span className="absolute right-1 top-1 flex size-3.5 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                                        <Check className="size-2.5" />
                                                    </span>
                                                )}
                                            </button>
                                        );
                                    },
                                )}
                            </div>
                        ) : (
                            <div className="py-10 text-center">
                                <Search className="mx-auto size-6 text-muted-foreground/50" />

                                <p className="mt-2 text-sm text-muted-foreground">
                                    No icons
                                    found for "
                                    {search}".
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="border-t px-3 py-2 text-xs text-muted-foreground">
                        {
                            filteredIcons.length
                        }{" "}
                        icons
                    </div>
                </div>
            )}
        </div>
    );
}