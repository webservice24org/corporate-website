import { ChevronDown } from "lucide-react";
import {
    type ReactNode,
    useState,
} from "react";

type Props = {
    title: string;
    children: ReactNode;
    defaultOpen?: boolean;
};

export default function FieldPanel({
    title,
    children,
    defaultOpen = true,
}: Props) {
    const [open, setOpen] =
        useState(defaultOpen);

    return (
        <div className="overflow-hidden rounded-lg border">
            <button
                type="button"
                onClick={() =>
                    setOpen((current) => !current)
                }
                className="flex w-full items-center justify-between gap-3 bg-muted/30 px-4 py-3 text-left transition hover:bg-muted/50"
            >
                <span className="text-sm font-semibold">
                    {title}
                </span>

                <ChevronDown
                    className={`size-4 text-muted-foreground transition-transform ${
                        open
                            ? "rotate-180"
                            : ""
                    }`}
                />
            </button>

            {open && (
                <div className="space-y-5 border-t p-4">
                    {children}
                </div>
            )}
        </div>
    );
}