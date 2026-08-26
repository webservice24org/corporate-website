import type { ReactNode } from "react";

import {
    useSortable,
} from "@dnd-kit/sortable";

import {
    CSS,
} from "@dnd-kit/utilities";

import {
    GripVertical,
} from "lucide-react";

type Props = {
    id: number;
    children: ReactNode;
};

export default function SortableSection({
    id,
    children,
}: Props) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({
        id,
    });

    const style = {
        transform:
            CSS.Transform.toString(transform),
        transition,
    };

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={
                isDragging
                    ? "relative z-20 opacity-60"
                    : "relative"
            }
        >
            <button
                type="button"
                {...attributes}
                {...listeners}
                aria-label="Drag to reorder section"
                className="absolute left-3 top-3 z-30 flex size-8 cursor-grab items-center justify-center rounded-lg border bg-background text-muted-foreground shadow-sm transition hover:text-foreground active:cursor-grabbing"
            >
                <GripVertical className="size-4" />
            </button>

            {children}
        </div>
    );
}