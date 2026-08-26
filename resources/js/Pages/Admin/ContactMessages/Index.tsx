import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    Link,
} from "@inertiajs/react";

import {
    Archive,
    CheckCircle2,
    Mail,
    MailOpen,
    MessageSquareReply,
} from "lucide-react";

import { router } from "@inertiajs/react";

import {
    Trash2,
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

import toast from "react-hot-toast";

import {
    useState,
} from "react";

type Filters = {
    status: MessageStatus | null;
};

type MessageCounts = {
    all: number;
    unread: number;
    read: number;
    replied: number;
    archived: number;
};

type MessageStatus =
    | "unread"
    | "read"
    | "replied"
    | "archived";

type ContactMessage = {
    id: number;

    name: string;
    phone: string | null;
    email: string;

    target: string | null;
    target_label: string | null;

    subject: string;
    message: string;

    status: MessageStatus;

    read_at: string | null;
    replied_at: string | null;

    created_at: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedMessages = {
    current_page: number;

    data: ContactMessage[];

    first_page_url: string;

    from: number | null;

    last_page: number;

    last_page_url: string;

    links: PaginationLink[];

    next_page_url: string | null;

    path: string;

    per_page: number;

    prev_page_url: string | null;

    to: number | null;

    total: number;
};

type Props = {
    messages: PaginatedMessages;

    filters: Filters;

    counts: MessageCounts;
};

export default function Index({
    messages,
    filters,
    counts,
}: Props) {
    const currentStatus =
        filters.status ?? "all";

    return (
        <AdminLayout>
            <Head title="Contact Messages" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <div className="flex size-11 items-center justify-center rounded-xl bg-muted">
                                <Mail className="size-5" />
                            </div>

                            <div>
                                <h1 className="text-2xl font-bold tracking-tight">
                                    Contact Messages
                                </h1>

                                <p className="mt-1 text-sm text-muted-foreground">
                                    Read and respond to
                                    inquiries submitted
                                    from the website.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <div className="rounded-lg border bg-background px-4 py-2">
                            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                Total
                            </div>

                            <div className="mt-0.5 text-lg font-bold">
                                {counts.all}
                            </div>
                        </div>

                        <div className="rounded-lg border bg-background px-4 py-2">
                            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                Unread
                            </div>

                            <div className="mt-0.5 text-lg font-bold">
                                {
                                    counts.unread
                                }
                            </div>
                        </div>
                    </div>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-3 rounded-xl border bg-background p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-wrap gap-2">
                        <FilterLink
                            label="All"
                            count={
                                counts.all
                            }
                            active={
                                currentStatus ===
                                "all"
                            }
                            href="/admin/contact-messages"
                        />

                        <FilterLink
                            label="Unread"
                            count={
                                counts.unread
                            }
                            active={
                                currentStatus ===
                                "unread"
                            }
                            href="/admin/contact-messages?status=unread"
                        />

                        <FilterLink
                            label="Read"
                            count={
                                counts.read
                            }
                            active={
                                currentStatus ===
                                "read"
                            }
                            href="/admin/contact-messages?status=read"
                        />

                        <FilterLink
                            label="Replied"
                            count={
                                counts.replied
                            }
                            active={
                                currentStatus ===
                                "replied"
                            }
                            href="/admin/contact-messages?status=replied"
                        />

                        <FilterLink
                            label="Archived"
                            count={
                                counts.archived
                            }
                            active={
                                currentStatus ===
                                "archived"
                            }
                            href="/admin/contact-messages?status=archived"
                        />
                    </div>

                    <div className="text-sm text-muted-foreground">
                        {messages.total}{" "}
                        {messages.total === 1
                            ? "message"
                            : "messages"}
                    </div>
                </div>

                {/* Inbox table */}
                <div className="overflow-hidden rounded-xl border bg-background shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1000px] text-left text-sm">
                            <thead className="border-b bg-muted/40">
                                <tr>
                                    <th className="px-5 py-3 font-semibold">
                                        Sender
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Target
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Subject
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Status
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Received
                                    </th>

                                    <th className="px-5 py-3 text-right font-semibold">
                                        Action
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y">
                                {messages.data
                                    .length >
                                0 ? (
                                    messages.data.map(
                                        (
                                            message,
                                        ) => (
                                            <MessageRow
                                                key={
                                                    message.id
                                                }
                                                message={
                                                    message
                                                }
                                            />
                                        ),
                                    )
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={
                                                6
                                            }
                                            className="px-6 py-14 text-center"
                                        >
                                            <Mail className="mx-auto size-9 text-muted-foreground/40" />

                                            <h3 className="mt-3 font-medium">
                                                No
                                                messages
                                                found
                                            </h3>

                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {currentStatus ===
                                                "all"
                                                    ? "No contact messages have been received yet."
                                                    : `There are no ${currentStatus} messages.`}
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Pagination */}
                {messages.last_page >
                    1 && (
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{" "}
                            {messages.from ??
                                0}
                            {" - "}
                            {messages.to ??
                                0}
                            {" of "}
                            {
                                messages.total
                            }
                        </p>

                        <div className="flex flex-wrap gap-1">
                            {messages.links.map(
                                (
                                    link,
                                    index,
                                ) => {
                                    const label =
                                        cleanPaginationLabel(
                                            link.label,
                                        );

                                    if (
                                        !link.url
                                    ) {
                                        return (
                                            <span
                                                key={
                                                    index
                                                }
                                                className="flex h-9 min-w-9 cursor-not-allowed items-center justify-center rounded-md border px-3 text-sm text-muted-foreground opacity-50"
                                            >
                                                {
                                                    label
                                                }
                                            </span>
                                        );
                                    }

                                    return (
                                        <Link
                                            key={
                                                index
                                            }
                                            href={
                                                link.url
                                            }
                                            preserveScroll
                                            className={`flex h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition ${
                                                link.active
                                                    ? "border-primary bg-primary text-primary-foreground"
                                                    : "bg-background hover:bg-muted"
                                            }`}
                                        >
                                            {
                                                label
                                            }
                                        </Link>
                                    );
                                },
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}

type MessageRowProps = {
    message: ContactMessage;
};

function MessageRow({
    message,
}: MessageRowProps) {
    const unread =
        message.status === "unread";

    const [
        deleteOpen,
        setDeleteOpen,
    ] = useState(false);

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const deleteMessage = () => {
        setDeleting(true);

        router.delete(
            `/admin/contact-messages/${message.id}`,
            {
                preserveScroll: true,

                onSuccess: () => {
                    setDeleteOpen(false);

                },

                onError: () => {
                    toast.error(
                        "Could not delete the message.",
                    );
                },

                onFinish: () => {
                    setDeleting(false);
                },
            },
        );
    };

    return (
        <tr
            className={`transition-colors hover:bg-muted/30 ${
                unread
                    ? "bg-primary/[0.035]"
                    : ""
            }`}
        >
            {/* Sender */}
            <td className="px-5 py-4">
                <div className="flex items-start gap-3">
                    <div
                        className={`mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full ${
                            unread
                                ? "bg-primary/10 text-primary"
                                : "bg-muted text-muted-foreground"
                        }`}
                    >
                        {unread ? (
                            <Mail className="size-4" />
                        ) : (
                            <MailOpen className="size-4" />
                        )}
                    </div>

                    <div className="min-w-0">
                        <div
                            className={`truncate ${
                                unread
                                    ? "font-bold"
                                    : "font-medium"
                            }`}
                        >
                            {
                                message.name
                            }
                        </div>

                        <div className="mt-0.5 truncate text-xs text-muted-foreground">
                            {
                                message.email
                            }
                        </div>

                        {message.phone && (
                            <div className="mt-0.5 truncate text-xs text-muted-foreground">
                                {
                                    message.phone
                                }
                            </div>
                        )}
                    </div>
                </div>
            </td>

            {/* Target */}
            <td className="px-5 py-4">
                <div className="max-w-[180px]">
                    {message.target_label ? (
                        <span className="text-sm">
                            {
                                message.target_label
                            }
                        </span>
                    ) : (
                        <span className="text-muted-foreground">
                            General
                        </span>
                    )}
                </div>
            </td>

            {/* Subject */}
            <td className="px-5 py-4">
                <div className="max-w-[320px]">
                    <div
                        className={`truncate ${
                            unread
                                ? "font-semibold"
                                : ""
                        }`}
                    >
                        {
                            message.subject
                        }
                    </div>

                    <div className="mt-1 truncate text-xs text-muted-foreground">
                        {
                            message.message
                        }
                    </div>
                </div>
            </td>

            {/* Status */}
            <td className="px-5 py-4">
                <StatusBadge
                    status={
                        message.status
                    }
                />
            </td>

            {/* Received */}
            <td className="px-5 py-4 text-muted-foreground">
                <div>
                    {formatDate(
                        message.created_at,
                    )}
                </div>

                <div className="mt-0.5 text-xs">
                    {formatTime(
                        message.created_at,
                    )}
                </div>
            </td>

            {/* Action */}
            <td className="px-5 py-4">
                <div className="flex justify-end gap-2">
                    <Link
                        href={`/admin/contact-messages/${message.id}`}
                        className="inline-flex h-9 items-center justify-center rounded-md border bg-background px-3 text-sm font-medium transition hover:bg-muted"
                    >
                        {unread
                            ? "Read Message"
                            : "View"}
                    </Link>

                    <AlertDialogTrigger
                        isOpen={deleteOpen}
                        onOpenChange={setDeleteOpen}
                    >
                        <Button
                            type="button"
                            variant="destructive"
                            size="icon-sm"
                            aria-label="Delete message"
                        >
                            <Trash2 />
                        </Button>

                        <AlertDialog>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Delete this message?
                                </AlertDialogTitle>

                                <AlertDialogDescription>
                                    This will permanently
                                    delete the message from{" "}
                                    <strong>
                                        {message.name}
                                    </strong>{" "}
                                    and all associated reply
                                    history. This action
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
                                    onPress={deleteMessage}
                                    isDisabled={deleting}
                                >
                                    {deleting
                                        ? "Deleting..."
                                        : "Delete Message"}
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialog>
                    </AlertDialogTrigger>
                </div>
            </td>
        </tr>
    );
}

type StatusBadgeProps = {
    status: MessageStatus;
};

function StatusBadge({
    status,
}: StatusBadgeProps) {
    if (status === "unread") {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                <Mail className="size-3.5" />

                Unread
            </span>
        );
    }

    if (status === "replied") {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                <MessageSquareReply className="size-3.5" />

                Replied
            </span>
        );
    }

    if (status === "archived") {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                <Archive className="size-3.5" />

                Archived
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
            <CheckCircle2 className="size-3.5" />

            Read
        </span>
    );
}

type FilterLinkProps = {
    label: string;
    count: number;
    active: boolean;
    href: string;
};

function FilterLink({
    label,
    count,
    active,
    href,
}: FilterLinkProps) {
    return (
        <Link
            href={href}
            preserveScroll
            className={`inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition ${
                active
                    ? "border-primary bg-primary text-primary-foreground"
                    : "bg-background hover:bg-muted"
            }`}
        >
            <span>
                {label}
            </span>

            <span
                className={`rounded-full px-2 py-0.5 text-xs ${
                    active
                        ? "bg-primary-foreground/15 text-primary-foreground"
                        : "bg-muted text-muted-foreground"
                }`}
            >
                {count}
            </span>
        </Link>
    );
}

function formatDate(
    value: string,
) {
    return new Intl.DateTimeFormat(
        "en-GB",
        {
            day: "2-digit",
            month: "short",
            year: "numeric",
        },
    ).format(new Date(value));
}

function formatTime(
    value: string,
) {
    return new Intl.DateTimeFormat(
        "en-US",
        {
            hour: "numeric",
            minute: "2-digit",
        },
    ).format(new Date(value));
}

function cleanPaginationLabel(
    value: string,
) {
    return value
        .replace(
            "&laquo;",
            "«",
        )
        .replace(
            "&raquo;",
            "»",
        )
        .replace(
            "Previous",
            "Previous",
        )
        .replace(
            "Next",
            "Next",
        );
}