import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Mail,
    RotateCcw,
    Trash2,
    UserMinus,
} from "lucide-react";

import toast from "react-hot-toast";

import { useState } from "react";

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

type SubscriberStatus =
    | "active"
    | "unsubscribed";

type Subscriber = {
    id: number;
    email: string;

    status: SubscriberStatus;

    subscribed_at: string | null;
    unsubscribed_at: string | null;

    created_at: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedSubscribers = {
    data: Subscriber[];

    links: PaginationLink[];

    current_page: number;
    last_page: number;

    from: number | null;
    to: number | null;

    total: number;
};

type Filters = {
    status:
        | SubscriberStatus
        | null;
};

type Counts = {
    all: number;
    active: number;
    unsubscribed: number;
};

type Props = {
    subscribers:
        PaginatedSubscribers;

    filters: Filters;

    counts: Counts;
};

export default function Index({
    subscribers,
    filters,
    counts,
}: Props) {
    const currentStatus =
        filters.status ?? "all";

    const unsubscribe = (
        subscriber: Subscriber,
    ) => {
        router.patch(
            `/admin/newsletter-subscribers/${subscriber.id}/unsubscribe`,
            {},
            {
                preserveScroll: true,

                onSuccess: () =>
                    toast.success(
                        "Subscriber unsubscribed.",
                    ),

                onError: () =>
                    toast.error(
                        "Could not update subscriber.",
                    ),
            },
        );
    };

    const reactivate = (
        subscriber: Subscriber,
    ) => {
        router.patch(
            `/admin/newsletter-subscribers/${subscriber.id}/reactivate`,
            {},
            {
                preserveScroll: true,

                onSuccess: () =>
                    toast.success(
                        "Subscriber reactivated.",
                    ),

                onError: () =>
                    toast.error(
                        "Could not update subscriber.",
                    ),
            },
        );
    };

    

    return (
        <AdminLayout>
            <Head title="Newsletter Subscribers" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">
                        Newsletter Subscribers
                    </h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Manage newsletter
                        subscriptions.
                    </p>
                </div>

                <div className="flex flex-wrap gap-2 rounded-xl border bg-background p-4">
                    <FilterLink
                        label="All"
                        count={counts.all}
                        active={
                            currentStatus ===
                            "all"
                        }
                        href="/admin/newsletter-subscribers"
                    />

                    <FilterLink
                        label="Active"
                        count={
                            counts.active
                        }
                        active={
                            currentStatus ===
                            "active"
                        }
                        href="/admin/newsletter-subscribers?status=active"
                    />

                    <FilterLink
                        label="Unsubscribed"
                        count={
                            counts.unsubscribed
                        }
                        active={
                            currentStatus ===
                            "unsubscribed"
                        }
                        href="/admin/newsletter-subscribers?status=unsubscribed"
                    />
                </div>

                <div className="overflow-hidden rounded-xl border bg-background">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[850px] text-sm">
                            <thead className="border-b bg-muted/40">
                                <tr>
                                    <th className="px-5 py-3 text-left">
                                        Email
                                    </th>

                                    <th className="px-5 py-3 text-left">
                                        Status
                                    </th>

                                    <th className="px-5 py-3 text-left">
                                        Subscribed
                                    </th>

                                    <th className="px-5 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y">
                                {subscribers.data.map(
                                    (subscriber) => (
                                        <SubscriberRow
                                            key={subscriber.id}
                                            subscriber={subscriber}
                                            onUnsubscribe={
                                                unsubscribe
                                            }
                                            onReactivate={
                                                reactivate
                                            }
                                        />
                                    ),
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {subscribers.last_page >
                    1 && (
                    <div className="flex flex-wrap gap-1">
                        {subscribers.links.map(
                            (
                                link,
                                index,
                            ) =>
                                link.url ? (
                                    <Link
                                        key={
                                            index
                                        }
                                        href={
                                            link.url
                                        }
                                        preserveScroll
                                        className={`rounded-md border px-3 py-2 text-sm ${
                                            link.active
                                                ? "bg-primary text-primary-foreground"
                                                : ""
                                        }`}
                                    >
                                        {cleanLabel(
                                            link.label,
                                        )}
                                    </Link>
                                ) : (
                                    <span
                                        key={
                                            index
                                        }
                                        className="rounded-md border px-3 py-2 text-sm opacity-40"
                                    >
                                        {cleanLabel(
                                            link.label,
                                        )}
                                    </span>
                                ),
                        )}
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}

type SubscriberRowProps = {
    subscriber: Subscriber;

    onUnsubscribe: (
        subscriber: Subscriber,
    ) => void;

    onReactivate: (
        subscriber: Subscriber,
    ) => void;
};

function SubscriberRow({
    subscriber,
    onUnsubscribe,
    onReactivate,
}: SubscriberRowProps) {
    const [
        deleteOpen,
        setDeleteOpen,
    ] = useState(false);

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const deleteSubscriber = () => {
        setDeleting(true);

        router.delete(
            `/admin/newsletter-subscribers/${subscriber.id}`,
            {
                preserveScroll: true,

                onSuccess: () => {
                    setDeleteOpen(false);

                    toast.success(
                        "Subscriber deleted.",
                    );
                },

                onError: () => {
                    toast.error(
                        "Could not delete subscriber.",
                    );
                },

                onFinish: () => {
                    setDeleting(false);
                },
            },
        );
    };

    return (
        <tr className="hover:bg-muted/30">
            <td className="px-5 py-4">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-full bg-muted">
                        <Mail className="size-4" />
                    </div>

                    <span className="font-medium">
                        {subscriber.email}
                    </span>
                </div>
            </td>

            <td className="px-5 py-4">
                <StatusBadge
                    status={
                        subscriber.status
                    }
                />
            </td>

            <td className="px-5 py-4 text-muted-foreground">
                {subscriber.subscribed_at
                    ? formatDate(
                          subscriber.subscribed_at,
                      )
                    : "—"}
            </td>

            <td className="px-5 py-4">
                <div className="flex justify-end gap-2">
                    {subscriber.status ===
                    "active" ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            aria-label="Unsubscribe subscriber"
                            onPress={() =>
                                onUnsubscribe(
                                    subscriber,
                                )
                            }
                        >
                            <UserMinus />
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            aria-label="Reactivate subscriber"
                            onPress={() =>
                                onReactivate(
                                    subscriber,
                                )
                            }
                        >
                            <RotateCcw />
                        </Button>
                    )}

                    <AlertDialogTrigger
                        isOpen={
                            deleteOpen
                        }
                        onOpenChange={
                            setDeleteOpen
                        }
                    >
                        <Button
                            type="button"
                            variant="destructive"
                            size="icon-sm"
                            aria-label="Delete subscriber"
                        >
                            <Trash2 />
                        </Button>

                        <AlertDialog>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Delete this
                                    subscriber?
                                </AlertDialogTitle>

                                <AlertDialogDescription>
                                    This will
                                    permanently
                                    delete{" "}
                                    <strong>
                                        {
                                            subscriber.email
                                        }
                                    </strong>{" "}
                                    from the
                                    newsletter
                                    subscriber list.
                                    This action
                                    cannot be
                                    undone.
                                </AlertDialogDescription>
                            </AlertDialogHeader>

                            <AlertDialogFooter>
                                <AlertDialogCancel
                                    isDisabled={
                                        deleting
                                    }
                                >
                                    Cancel
                                </AlertDialogCancel>

                                <AlertDialogAction
                                    variant="destructive"
                                    onPress={
                                        deleteSubscriber
                                    }
                                    isDisabled={
                                        deleting
                                    }
                                >
                                    {deleting
                                        ? "Deleting..."
                                        : "Delete Subscriber"}
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialog>
                    </AlertDialogTrigger>
                </div>
            </td>
        </tr>
    );
}

function FilterLink({
    label,
    count,
    active,
    href,
}: {
    label: string;
    count: number;
    active: boolean;
    href: string;
}) {
    return (
        <Link
            href={href}
            preserveScroll
            className={`inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm ${
                active
                    ? "bg-primary text-primary-foreground"
                    : "hover:bg-muted"
            }`}
        >
            {label}

            <span className="rounded-full bg-black/5 px-2 py-0.5 text-xs">
                {count}
            </span>
        </Link>
    );
}

function StatusBadge({
    status,
}: {
    status: SubscriberStatus;
}) {
    if (
        status === "active"
    ) {
        return (
            <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                Active
            </span>
        );
    }

    return (
        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
            Unsubscribed
        </span>
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

function cleanLabel(
    value: string,
) {
    return value
        .replace("&laquo;", "«")
        .replace("&raquo;", "»");
}