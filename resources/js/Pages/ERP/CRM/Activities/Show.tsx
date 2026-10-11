import {
    Head,
    Link,
} from "@inertiajs/react";

import {
    ArrowLeft,
    CalendarClock,
    FilePenLine,
    UserRound,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

type Activity = {
    id: number;

    activity_type:
        string;

    subject: string;

    description:
        string | null;

    status: string;

    priority: string;

    scheduled_at:
        string | null;

    due_at:
        string | null;

    reminder_at:
        string | null;

    completed_at:
        string | null;

    outcome:
        string | null;

    assigned_to?: {
        id: number;
        name: string;
        email: string;
    } | null;

    created_by?: {
        id: number;
        name: string;
        email: string;
    } | null;

    activityable?: {
        id: number;
        lead_number: string;
        lead_type:
            | "individual"
            | "company";
        company_name:
            string | null;
        contact_name:
            string;
    } | null;
};

type Props = {
    activity:
        Activity;
};

function humanize(
    value: string
) {
    return value
        .replaceAll(
            "_",
            " "
        )
        .replace(
            /\b\w/g,
            (letter) =>
                letter.toUpperCase()
        );
}

export default function Show({
    activity,
}: Props) {
    const { can } =
        useAuthorization();

    return (
        <ERPLayout>
            <Head
                title={
                    activity.subject
                }
            />

            <div className="mx-auto max-w-6xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex gap-4">
                        <Link
                            href="/erp/crm/activities"
                            className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>

                        <div>
                            <h1 className="text-2xl font-semibold">
                                {
                                    activity.subject
                                }
                            </h1>

                            <p className="mt-1 text-sm text-slate-500">
                                {humanize(
                                    activity.activity_type
                                )}
                            </p>
                        </div>
                    </div>

                    {can(
                        "erp.crm-activities.edit"
                    ) && (
                        <Link
                            href={`/erp/crm/activities/${activity.id}/edit`}
                            className="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium dark:border-slate-800"
                        >
                            <FilePenLine className="h-4 w-4" />

                            Edit Activity
                        </Link>
                    )}
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        label="Status"
                        value={humanize(
                            activity.status
                        )}
                    />

                    <Metric
                        label="Priority"
                        value={humanize(
                            activity.priority
                        )}
                    />

                    <Metric
                        label="Assigned To"
                        value={
                            activity
                                .assigned_to
                                ?.name ??
                            "Unassigned"
                        }
                    />

                    <Metric
                        label="Created By"
                        value={
                            activity
                                .created_by
                                ?.name ??
                            "—"
                        }
                    />
                </div>

                {activity.activityable && (
                    <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                        <h2 className="font-semibold">
                            Related Lead
                        </h2>

                        <Link
                            href={`/erp/crm/leads/${activity.activityable.id}`}
                            className="mt-4 block rounded-lg border border-slate-200 p-4 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                        >
                            <div className="font-medium">
                                {activity
                                    .activityable
                                    .lead_type ===
                                "company"
                                    ? activity
                                          .activityable
                                          .company_name ??
                                      activity
                                          .activityable
                                          .contact_name
                                    : activity
                                          .activityable
                                          .contact_name}
                            </div>

                            <div className="mt-1 font-mono text-xs text-slate-500">
                                {
                                    activity
                                        .activityable
                                        .lead_number
                                }
                            </div>
                        </Link>
                    </section>
                )}

                <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                    <h2 className="font-semibold">
                        Schedule
                    </h2>

                    <div className="mt-5 grid gap-4 md:grid-cols-2">
                        <Info
                            icon={
                                CalendarClock
                            }
                            label="Scheduled"
                            value={
                                activity.scheduled_at
                                    ? new Date(
                                          activity.scheduled_at
                                      ).toLocaleString()
                                    : "—"
                            }
                        />

                        <Info
                            icon={
                                CalendarClock
                            }
                            label="Due"
                            value={
                                activity.due_at
                                    ? new Date(
                                          activity.due_at
                                      ).toLocaleString()
                                    : "—"
                            }
                        />

                        <Info
                            icon={
                                CalendarClock
                            }
                            label="Reminder"
                            value={
                                activity.reminder_at
                                    ? new Date(
                                          activity.reminder_at
                                      ).toLocaleString()
                                    : "—"
                            }
                        />

                        <Info
                            icon={
                                CalendarClock
                            }
                            label="Completed"
                            value={
                                activity.completed_at
                                    ? new Date(
                                          activity.completed_at
                                      ).toLocaleString()
                                    : "—"
                            }
                        />
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <TextCard
                        title="Description"
                        value={
                            activity.description
                        }
                    />

                    <TextCard
                        title="Outcome"
                        value={
                            activity.outcome
                        }
                    />
                </div>
            </div>
        </ERPLayout>
    );
}

function Metric({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
            <div className="text-xs uppercase tracking-wide text-slate-500">
                {label}
            </div>

            <div className="mt-2 text-lg font-semibold">
                {value}
            </div>
        </div>
    );
}

function Info({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof UserRound;
    label: string;
    value: string;
}) {
    return (
        <div className="flex gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-900">
                <Icon className="h-4 w-4" />
            </div>

            <div>
                <div className="text-xs text-slate-500">
                    {label}
                </div>

                <div className="mt-1 text-sm font-medium">
                    {value}
                </div>
            </div>
        </div>
    );
}

function TextCard({
    title,
    value,
}: {
    title: string;
    value:
        string | null;
}) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
            <h2 className="font-semibold">
                {title}
            </h2>

            <p className="mt-4 whitespace-pre-line text-sm leading-6 text-slate-600 dark:text-slate-400">
                {value || "—"}
            </p>
        </section>
    );
}