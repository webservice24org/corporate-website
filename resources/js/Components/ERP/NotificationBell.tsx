import {
    Link,
    router,
    usePage,
} from "@inertiajs/react";

import {
    Bell,
    CheckCheck,
    ExternalLink,
} from "lucide-react";

import {
    useEffect,
    useRef,
    useState,
} from "react";

import type {
    PageProps,
} from "@/types";

import type {
    ErpNotification,
    ErpNotificationShared,
} from "@/types/erp-notification";

type SharedProps =
    PageProps & {
        erpNotifications?:
            ErpNotificationShared;
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

    return date.toLocaleDateString();
}

export default function NotificationBell() {
    const {
        erpNotifications,
    } =
        usePage<SharedProps>()
            .props;

    const notifications = erpNotifications ?? {
        unread_count: 0,
        items: [],
    };

    const [
        pending,
        setPending,
    ] = useState(false);

    const [
        open,
        setOpen,
    ] =
        useState(false);

    const wrapperRef =
        useRef<HTMLDivElement>(
            null
        );

    /*
     * Refresh only the shared
     * notification payload every
     * 60 seconds.
     */
    useEffect(
        () => {
            const interval =
                window.setInterval(
                    () => {
                        if (document.visibilityState === "visible") {
                            router.reload({
                                only: ["erpNotifications"],
                            });
                        }
                    },
                    60_000
                );

            return () => {
                window.clearInterval(
                    interval
                );
            };
        },
        []
    );

    /*
     * Close dropdown when clicking
     * outside.
     */
    useEffect(
        () => {
            const handleClickOutside =
                (
                    event:
                        MouseEvent
                ) => {
                    if (
                        wrapperRef.current &&
                        !wrapperRef.current.contains(
                            event.target as Node
                        )
                    ) {
                        setOpen(
                            false
                        );
                    }
                };

            document.addEventListener(
                "mousedown",
                handleClickOutside
            );

            const handleEscape = (event: KeyboardEvent) => {
                if (event.key === "Escape") {
                    setOpen(false);
                }
            };

            document.addEventListener("keydown", handleEscape);

            return () => {
                document.removeEventListener(
                    "mousedown",
                    handleClickOutside
                );
                document.removeEventListener("keydown", handleEscape);
            };
        },
        []
    );

    const markAsReadAndOpen =
        (
            notification:
                ErpNotification
        ) => {
            setOpen(
                false
            );

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
            if (pending) {
                return;
            }

            setPending(true);

            router.post(
                "/erp/notifications/read-all",
                {},
                {
                    preserveScroll:
                        true,

                    onSuccess: () => {
                        router.reload({
                            only: ["erpNotifications"],
                        });
                    },
                    onFinish: () => setPending(false),
                }
            );
        };

    return (
        <div
            ref={
                wrapperRef
            }
            className="relative"
        >
            <button
                type="button"
                onClick={() =>
                    setOpen(
                        (
                            current
                        ) =>
                            !current
                    )
                }
                className="relative inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-200 dark:hover:bg-slate-900"
                aria-label="Notifications"
                aria-expanded={open}
                aria-haspopup="dialog"
            >
                <Bell className="h-5 w-5" />

                {notifications.unread_count >
                    0 && (
                    <span className="absolute -right-1 -top-1 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white">
                        {notifications.unread_count >
                        99
                            ? "99+"
                            : notifications.unread_count}
                    </span>
                )}
            </button>

            {open && (
                <div role="dialog" aria-label="Recent notifications" className="absolute right-0 z-50 mt-2 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-950">
                    <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                        <div>
                            <h3 className="text-sm font-semibold">
                                Notifications
                            </h3>

                            <p className="mt-0.5 text-xs text-slate-500">
                                {
                                    notifications.unread_count
                                }{" "}
                                unread
                            </p>
                        </div>

                        {notifications.unread_count >
                            0 && (
                            <button
                                type="button"
                                onClick={markAllRead}
                                disabled={pending}
                                className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 hover:text-slate-950 dark:text-slate-400 dark:hover:text-white"
                            >
                                <CheckCheck className="h-4 w-4" />

                                Mark all read
                            </button>
                        )}
                    </div>

                    <div className="max-h-[28rem] overflow-y-auto">
                        {notifications.items.map(
                            (
                                notification
                            ) => (
                                <button
                                    key={
                                        notification.id
                                    }
                                    type="button"
                                    onClick={() =>
                                        markAsReadAndOpen(
                                            notification
                                        )
                                    }
                                    className={`block w-full border-b border-slate-100 px-4 py-3 text-left transition last:border-b-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900 ${
                                        !notification.read_at
                                            ? "bg-blue-50/50 dark:bg-blue-950/10"
                                            : ""
                                    }`}
                                >
                                    <div className="flex gap-3">
                                        <div className="relative mt-1 shrink-0">
                                            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-900">
                                                <Bell className="h-4 w-4" />
                                            </div>

                                            {!notification.read_at && (
                                                <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-blue-600 ring-2 ring-white dark:ring-slate-950" />
                                            )}
                                        </div>

                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="truncate text-sm font-medium">
                                                    {
                                                        notification.title
                                                    }
                                                </p>

                                                {notification.url && (
                                                    <ExternalLink className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
                                                )}
                                            </div>

                                            {notification.message && (
                                                <p className="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">
                                                    {
                                                        notification.message
                                                    }
                                                </p>
                                            )}

                                            <div className="mt-1.5 flex items-center gap-2 text-[11px] text-slate-400">
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
                                            </div>
                                        </div>
                                    </div>
                                </button>
                            )
                        )}

                        {notifications.items.length ===
                            0 && (
                            <div className="px-6 py-10 text-center">
                                <Bell className="mx-auto h-8 w-8 text-slate-300 dark:text-slate-700" />

                                <p className="mt-3 text-sm font-medium">
                                    No notifications
                                </p>

                                <p className="mt-1 text-xs text-slate-500">
                                    New ERP notifications
                                    will appear here.
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="border-t border-slate-200 p-2 dark:border-slate-800">
                        <Link
                            href="/erp/notifications"
                            onClick={() => setOpen(false)}
                            className="flex h-9 items-center justify-center rounded-md text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-900"
                        >
                            View all notifications
                        </Link>
                    </div>
                </div>
            )}
        </div>
    );
}