import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    Link,
    router,
    useForm,
} from "@inertiajs/react";

import {
    Archive,
    ArrowLeft,
    CheckCircle2,
    Mail,
    MessageSquareReply,
    Phone,
    Send,
    User,
} from "lucide-react";

import toast from "react-hot-toast";
import type {
    ReactNode,
} from "react";

type MessageStatus =
    | "unread"
    | "read"
    | "replied"
    | "archived";

type ReplyUser = {
    id: number;
    name: string;
    email?: string;
};

type ContactReply = {
    id: number;

    to_email: string;
    subject: string;
    message: string;

    sent_at: string | null;
    created_at: string;

    user: ReplyUser | null;
};

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

    replies: ContactReply[];
};

type Props = {
    message: ContactMessage;
};

type ReplyForm = {
    subject: string;
    message: string;
};

export default function Show({
    message,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
        reset,
    } = useForm<ReplyForm>({
        subject: message.subject.startsWith(
            "Re:",
        )
            ? message.subject
            : `Re: ${message.subject}`,

        message: "",
    });

    const submitReply = () => {
        post(
            `/admin/contact-messages/${message.id}/reply`,
            {
                preserveScroll: true,

                onSuccess: () => {
                    toast.success(
                        "Reply sent successfully.",
                    );

                    reset("message");
                },

                onError: () => {
                    toast.error(
                        "Could not send the reply.",
                    );
                },
            },
        );
    };

    const archiveMessage = () => {
        router.patch(
            `/admin/contact-messages/${message.id}/archive`,
            {},
            {
                preserveScroll: true,

                onSuccess: () => {
                    toast.success(
                        "Message archived.",
                    );
                },

                onError: () => {
                    toast.error(
                        "Could not archive the message.",
                    );
                },
            },
        );
    };

    return (
        <AdminLayout>
            <Head
                title={`Contact Message - ${message.subject}`}
            />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex items-start gap-3">
                        <Link
                            href="/admin/contact-messages"
                            className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-background transition hover:bg-muted"
                            aria-label="Back to messages"
                        >
                            <ArrowLeft className="size-4" />
                        </Link>

                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-bold tracking-tight">
                                    {
                                        message.subject
                                    }
                                </h1>

                                <StatusBadge
                                    status={
                                        message.status
                                    }
                                />
                            </div>

                            <p className="mt-1 text-sm text-muted-foreground">
                                Received{" "}
                                {formatDateTime(
                                    message.created_at,
                                )}
                            </p>
                        </div>
                    </div>

                    {message.status !==
                        "archived" && (
                        <button
                            type="button"
                            onClick={
                                archiveMessage
                            }
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-lg border bg-background px-4 text-sm font-medium transition hover:bg-muted"
                        >
                            <Archive className="size-4" />

                            Archive
                        </button>
                    )}
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                    {/* Main column */}
                    <div className="space-y-6">
                        {/* Original message */}
                        <section className="overflow-hidden rounded-xl border bg-background shadow-sm">
                            <div className="border-b bg-muted/20 px-5 py-4">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex min-w-0 items-start gap-3">
                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                            <User className="size-4" />
                                        </div>

                                        <div className="min-w-0">
                                            <h2 className="truncate font-semibold">
                                                {
                                                    message.name
                                                }
                                            </h2>

                                            <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                <span>
                                                    {
                                                        message.email
                                                    }
                                                </span>

                                                {message.phone && (
                                                    <span>
                                                        {
                                                            message.phone
                                                        }
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {formatDateTime(
                                            message.created_at,
                                        )}
                                    </span>
                                </div>
                            </div>

                            <div className="p-6">
                                <div className="mb-5 grid gap-4 rounded-lg bg-muted/20 p-4 sm:grid-cols-2">
                                    <div>
                                        <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                            Target
                                        </div>

                                        <div className="mt-1 text-sm font-medium">
                                            {message.target_label ??
                                                "General Inquiry"}
                                        </div>
                                    </div>

                                    <div>
                                        <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                            Email
                                        </div>

                                        <a
                                            href={`mailto:${message.email}`}
                                            className="mt-1 block text-sm font-medium text-primary hover:underline"
                                        >
                                            {
                                                message.email
                                            }
                                        </a>
                                    </div>
                                </div>

                                <div>
                                    <h3 className="text-sm font-semibold text-muted-foreground">
                                        Message
                                    </h3>

                                    <div className="mt-3 whitespace-pre-wrap break-words text-[15px] leading-7">
                                        {
                                            message.message
                                        }
                                    </div>
                                </div>
                            </div>
                        </section>

                        {/* Previous replies */}
                        {message.replies.length >
                            0 && (
                            <section className="rounded-xl border bg-background shadow-sm">
                                <div className="border-b px-5 py-4">
                                    <div className="flex items-center gap-3">
                                        <MessageSquareReply className="size-5 text-muted-foreground" />

                                        <div>
                                            <h2 className="font-semibold">
                                                Reply
                                                History
                                            </h2>

                                            <p className="text-sm text-muted-foreground">
                                                {
                                                    message
                                                        .replies
                                                        .length
                                                }{" "}
                                                {message
                                                    .replies
                                                    .length ===
                                                1
                                                    ? "reply"
                                                    : "replies"}{" "}
                                                sent
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div className="divide-y">
                                    {message.replies.map(
                                        (
                                            reply,
                                        ) => (
                                            <ReplyItem
                                                key={
                                                    reply.id
                                                }
                                                reply={
                                                    reply
                                                }
                                            />
                                        ),
                                    )}
                                </div>
                            </section>
                        )}

                        {/* Reply form */}
                        <section className="rounded-xl border bg-background shadow-sm">
                            <div className="border-b px-5 py-4">
                                <div className="flex items-center gap-3">
                                    <Send className="size-5 text-muted-foreground" />

                                    <div>
                                        <h2 className="font-semibold">
                                            Reply
                                        </h2>

                                        <p className="text-sm text-muted-foreground">
                                            Send an email
                                            reply directly
                                            to{" "}
                                            {
                                                message.email
                                            }.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-5 p-5">
                                <Field
                                    label="Subject"
                                    error={
                                        errors.subject
                                    }
                                >
                                    <input
                                        type="text"
                                        value={
                                            data.subject
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "subject",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        className={inputClass}
                                    />
                                </Field>

                                <Field
                                    label="Message"
                                    error={
                                        errors.message
                                    }
                                >
                                    <textarea
                                        rows={10}
                                        value={
                                            data.message
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "message",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="Write your reply..."
                                        className={`${inputClass} h-auto min-h-[220px] resize-y py-3`}
                                    />
                                </Field>

                                <div className="flex justify-end">
                                    <button
                                        type="button"
                                        onClick={
                                            submitReply
                                        }
                                        disabled={
                                            processing
                                        }
                                        className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-primary px-5 text-sm font-semibold text-primary-foreground transition hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
                                    >
                                        <Send className="size-4" />

                                        {processing
                                            ? "Sending..."
                                            : "Send Reply"}
                                    </button>
                                </div>
                            </div>
                        </section>
                    </div>

                    {/* Sidebar */}
                    <aside className="space-y-6">
                        {/* Sender */}
                        <section className="rounded-xl border bg-background p-5">
                            <h2 className="font-semibold">
                                Sender
                            </h2>

                            <div className="mt-5 space-y-4">
                                <InfoItem
                                    icon={
                                        User
                                    }
                                    label="Name"
                                    value={
                                        message.name
                                    }
                                />

                                <InfoItem
                                    icon={
                                        Mail
                                    }
                                    label="Email"
                                    value={
                                        message.email
                                    }
                                    href={`mailto:${message.email}`}
                                />

                                {message.phone && (
                                    <InfoItem
                                        icon={
                                            Phone
                                        }
                                        label="Phone"
                                        value={
                                            message.phone
                                        }
                                        href={`tel:${message.phone}`}
                                    />
                                )}
                            </div>
                        </section>

                        {/* Message metadata */}
                        <section className="rounded-xl border bg-background p-5">
                            <h2 className="font-semibold">
                                Message Details
                            </h2>

                            <dl className="mt-4 space-y-4 text-sm">
                                <DetailRow
                                    label="Status"
                                    value={
                                        <StatusBadge
                                            status={
                                                message.status
                                            }
                                        />
                                    }
                                />

                                <DetailRow
                                    label="Target"
                                    value={
                                        message.target_label ??
                                        "General"
                                    }
                                />

                                <DetailRow
                                    label="Received"
                                    value={formatDateTime(
                                        message.created_at,
                                    )}
                                />

                                {message.read_at && (
                                    <DetailRow
                                        label="Read"
                                        value={formatDateTime(
                                            message.read_at,
                                        )}
                                    />
                                )}

                                {message.replied_at && (
                                    <DetailRow
                                        label="Last Replied"
                                        value={formatDateTime(
                                            message.replied_at,
                                        )}
                                    />
                                )}
                            </dl>
                        </section>
                    </aside>
                </div>
            </div>
        </AdminLayout>
    );
}

type ReplyItemProps = {
    reply: ContactReply;
};

function ReplyItem({
    reply,
}: ReplyItemProps) {
    return (
        <div className="p-5">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div className="font-medium">
                        {reply.user?.name ??
                            "Administrator"}
                    </div>

                    <div className="mt-1 text-xs text-muted-foreground">
                        To: {reply.to_email}
                    </div>
                </div>

                <div className="text-xs text-muted-foreground">
                    {formatDateTime(
                        reply.sent_at ??
                            reply.created_at,
                    )}
                </div>
            </div>

            <div className="mt-4 rounded-lg bg-muted/20 p-4">
                <div className="text-sm font-semibold">
                    {reply.subject}
                </div>

                <div className="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-muted-foreground">
                    {reply.message}
                </div>
            </div>
        </div>
    );
}

type InfoItemProps = {
    icon: typeof Mail;
    label: string;
    value: string;
    href?: string;
};

function InfoItem({
    icon: Icon,
    label,
    value,
    href,
}: InfoItemProps) {
    const content = (
        <>
            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                <Icon className="size-4" />
            </div>

            <div className="min-w-0">
                <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    {label}
                </div>

                <div className="mt-0.5 break-words text-sm font-medium">
                    {value}
                </div>
            </div>
        </>
    );

    if (href) {
        return (
            <a
                href={href}
                className="flex items-start gap-3 rounded-lg transition hover:text-primary"
            >
                {content}
            </a>
        );
    }

    return (
        <div className="flex items-start gap-3">
            {content}
        </div>
    );
}

type DetailRowProps = {
    label: string;
    value: ReactNode;
};

function DetailRow({
    label,
    value,
}: DetailRowProps) {
    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {label}
            </dt>

            <dd className="mt-1">
                {value}
            </dd>
        </div>
    );
}

type FieldProps = {
    label: string;
    error?: string;
    children: ReactNode;
};

function Field({
    label,
    error,
    children,
}: FieldProps) {
    return (
        <div>
            <label className="mb-2 block text-sm font-medium">
                {label}
            </label>

            {children}

            {error && (
                <p className="mt-1.5 text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
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

const inputClass =
    "w-full rounded-lg border border-input bg-background px-3 text-sm outline-none transition focus:border-ring focus:ring-3 focus:ring-ring/20";

function formatDateTime(
    value: string,
) {
    return new Intl.DateTimeFormat(
        "en-GB",
        {
            day: "2-digit",
            month: "short",
            year: "numeric",
            hour: "numeric",
            minute: "2-digit",
        },
    ).format(new Date(value));
}