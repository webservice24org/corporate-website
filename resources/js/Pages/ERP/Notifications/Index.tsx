import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Bell,
    Check,
    CheckCheck,
    ExternalLink,
} from "lucide-react";

import {
    useEffect,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

import type {
    ErpNotification,
} from "@/types/erp-notification";

type PaginationLink = {
    url:
        string | null;

    label:
        string;

    active:
        boolean;
};

type Props = {
    notifications: {
        data:
            ErpNotification[];

        links:
            PaginationLink[];

        total:
            number;
    };
};

function timeAgo(
    isoDate: string
): string {
    const date =
        new Date(
            isoDate
        );

    const seconds =
        Math.floor(
            (
                Date.now() -
                date.getTime()
            ) /
                1000
        );

    if (
        !Number.isFinite(
            seconds
        )
    ) {
        return "";
    }

    if (seconds < 60) {
        return "Just now";
    }

    const minutes =
        Math.floor(
            seconds / 60
        );

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours =
        Math.floor(
            minutes / 60
        );

    if (hours < 24) {
        return `${hours}h ago`;
    }

    const days =
        Math.floor(
            hours / 24
        );

    if (days < 7) {
        return `${days}d ago`;
    }

    return date.toLocaleString();
}

export default function Index({
    notifications,
}: Props) {
    /*
     * Full notification page also
     * refreshes every 60 seconds.
     */
    useEffect(
        () => {
            const interval =
                window.setInterval(
                    () => {
                        router.reload({
                            only: [
                                "notifications",
                                "erpNotifications",
                            ],
                        });
                    },
                    60_000
                );

            return () =>
                window.clearInterval(
                    interval
                );
        },
        []
    );

    const markRead = (
        notification:
            ErpNotification
    ) => {
        router.post(
            `/erp/notifications/${notification.id}/read`,
            {},
            {
                preserveScroll:
                    true,

                onSuccess:
                    () => {
                        router.reload({
                            only: [
                                "notifications",
                                "erpNotifications",
                            ],
                        });
                    },
            }
        );
    };

    const openNotification =
        (
            notification:
                ErpNotification
        ) => {
            if (
                notification.read_at
            ) {
                if (
                    notification.url
                ) {
                    router.visit(
                        notification.url
                    );
                }

                return;
            }

            router.post(
                `/erp/notifications/${notification.id}/read`,
                {},
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () => {
                            if (
                                notification.url
                            ) {
                                router.visit(
                                    notification.url
                                );
                            }
                        },
                }
            );
        };

    const markAllRead =
        () => {
            router.post(
                "/erp/notifications/read-all",
                {},
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () => {
                            router.reload({
                                only: [
                                    "notifications",
                                    "erpNotifications",
                                ],
                            });
                        },
                }
            );
        };

    const hasUnread =
        notifications.data.some(
            (
                notification
            ) =>
                !notification.read_at
        );

    return (
        <ERPLayout>
            <Head title="Notifications" />

            <div className="mx-auto max-w-6xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Notifications
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            ERP reminders,
                            alerts and activity
                            notifications.
                        </p>
                    </div>

                    {hasUnread && (
                        <button
                            type="button"
                            onClick={
                                markAllRead
                            }
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium dark:border-slate-800"
                        >
                            <CheckCheck className="h-4 w-4" />

                            Mark all as read
                        </button>
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    {notifications.data.map(
                        (
                            notification
                        ) => (
                            <div
                                key={
                                    notification.id
                                }
                                className={`border-b border-slate-100 p-5 last:border-b-0 dark:border-slate-800 ${
                                    !notification.read_at
                                        ? "bg-blue-50/40 dark:bg-blue-950/10"
                                        : ""
                                }`}
                            >
                                <div className="flex gap-4">
                                    <div className="relative shrink-0">
                                        <div className="flex h-11 w-11 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-900">
                                            <Bell className="h-5 w-5" />
                                        </div>

                                        {!notification.read_at && (
                                            <span className="absolute right-0 top-0 h-2.5 w-2.5 rounded-full bg-blue-600 ring-2 ring-white dark:ring-slate-950" />
                                        )}
                                    </div>

                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <h2 className="font-medium">
                                                    {
                                                        notification.title
                                                    }
                                                </h2>

                                                {notification.message && (
                                                    <p className="mt-1 text-sm leading-6 text-slate-500">
                                                        {
                                                            notification.message
                                                        }
                                                    </p>
                                                )}

                                                <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                                                    <span>
                                                        {timeAgo(
                                                            notification.created_at
                                                        )}
                                                    </span>

                                                    {notification.lead_number && (
                                                        <>
                                                            <span>
                                                                •
                                                            </span>

                                                            <span className="font-mono">
                                                                {
                                                                    notification.lead_number
                                                                }
                                                            </span>
                                                        </>
                                                    )}

                                                    <span>
                                                        •
                                                    </span>

                                                    <span>
                                                        {notification.read_at
                                                            ? "Read"
                                                            : "Unread"}
                                                    </span>
                                                </div>
                                            </div>

                                            <div className="flex shrink-0 flex-wrap gap-2">
                                                {!notification.read_at && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            markRead(
                                                                notification
                                                            )
                                                        }
                                                        className="inline-flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-xs font-medium dark:border-slate-800"
                                                    >
                                                        <Check className="h-4 w-4" />

                                                        Mark read
                                                    </button>
                                                )}

                                                {notification.url && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            openNotification(
                                                                notification
                                                            )
                                                        }
                                                        className="inline-flex h-9 items-center gap-2 rounded-md bg-slate-900 px-3 text-xs font-medium text-white dark:bg-white dark:text-slate-950"
                                                    >
                                                        <ExternalLink className="h-4 w-4" />

                                                        View
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )
                    )}

                    {notifications.data.length ===
                        0 && (
                        <div className="px-6 py-16 text-center">
                            <Bell className="mx-auto h-10 w-10 text-slate-300 dark:text-slate-700" />

                            <h2 className="mt-4 font-medium">
                                No notifications
                            </h2>

                            <p className="mt-1 text-sm text-slate-500">
                                ERP reminders and
                                alerts will appear
                                here.
                            </p>
                        </div>
                    )}

                    {notifications.links.length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-slate-800">
                            {notifications.links.map(
                                (
                                    link,
                                    index
                                ) => (
                                    <button
                                        key={
                                            index
                                        }
                                        type="button"
                                        disabled={
                                            !link.url
                                        }
                                        onClick={() => {
                                            if (
                                                link.url
                                            ) {
                                                router.visit(
                                                    link.url,
                                                    {
                                                        preserveScroll:
                                                            true,
                                                    }
                                                );
                                            }
                                        }}
                                        dangerouslySetInnerHTML={{
                                            __html:
                                                link.label,
                                        }}
                                        className={`min-w-9 rounded-md px-3 py-2 text-sm ${
                                            link.active
                                                ? "bg-slate-900 text-white dark:bg-white dark:text-slate-950"
                                                : "border border-slate-200 dark:border-slate-800"
                                        } disabled:opacity-40`}
                                    />
                                )
                            )}
                        </div>
                    )}
                </div>
            </div>
        </ERPLayout>
    );
}