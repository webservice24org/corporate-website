import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Eye,
    FilePenLine,
    Plus,
    Search,
    Trash2,
} from "lucide-react";

import {
    useState,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

type Company = {
    id: number;
    name: string;
};

type UserOption = {
    id: number;
    name: string;
    email: string;
};

type LeadSubject = {
    id: number;

    lead_number: string;

    lead_type:
        | "individual"
        | "company";

    company_name:
        string | null;

    contact_name: string;
};

type Activity = {
    id: number;

    activity_type:
        | "call"
        | "meeting"
        | "email"
        | "task"
        | "follow_up"
        | "note";

    subject: string;

    status:
        | "planned"
        | "in_progress"
        | "completed"
        | "cancelled";

    priority:
        | "low"
        | "normal"
        | "high"
        | "urgent";

    scheduled_at:
        string | null;

    due_at:
        string | null;

    completed_at:
        string | null;

    company?:
        Company | null;

    assigned_to?:
        UserOption | null;

    activityable?:
        LeadSubject | null;
};

type PaginationLink = {
    url:
        string | null;

    label:
        string;

    active:
        boolean;
};

type Props = {
    activities: {
        data:
            Activity[];

        links:
            PaginationLink[];

        total:
            number;
    };

    companies:
        Company[];

    users:
        UserOption[];

    filters: {
        company_id?:
            string | number;

        activity_type?:
            string;

        status?:
            string;

        priority?:
            string;

        assigned_to_user_id?:
            string | number;

        search?:
            string;
    };
};

function humanize(
    value: string
): string {
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

export default function Index({
    activities,
    companies,
    users,
    filters,
}: Props) {
    const { can } =
        useAuthorization();

    const [
        search,
        setSearch,
    ] = useState(
        filters.search ??
            ""
    );

    const [
        companyId,
        setCompanyId,
    ] = useState(
        filters.company_id
            ? String(
                  filters.company_id
              )
            : ""
    );

    const [
        activityType,
        setActivityType,
    ] = useState(
        filters.activity_type ??
            ""
    );

    const [
        status,
        setStatus,
    ] = useState(
        filters.status ??
            ""
    );

    const [
        priority,
        setPriority,
    ] = useState(
        filters.priority ??
            ""
    );

    const [
        assigneeId,
        setAssigneeId,
    ] = useState(
        filters.assigned_to_user_id
            ? String(
                  filters.assigned_to_user_id
              )
            : ""
    );

    const [
        deletingActivity,
        setDeletingActivity,
    ] =
        useState<Activity | null>(
            null
        );

    const applyFilters =
        () => {
            router.get(
                "/erp/crm/activities",
                {
                    search:
                        search ||
                        undefined,

                    company_id:
                        companyId ||
                        undefined,

                    activity_type:
                        activityType ||
                        undefined,

                    status:
                        status ||
                        undefined,

                    priority:
                        priority ||
                        undefined,

                    assigned_to_user_id:
                        assigneeId ||
                        undefined,
                },
                {
                    preserveState:
                        true,

                    preserveScroll:
                        true,

                    replace:
                        true,
                }
            );
        };

    const resetFilters =
        () => {
            setSearch("");
            setCompanyId("");
            setActivityType("");
            setStatus("");
            setPriority("");
            setAssigneeId("");

            router.get(
                "/erp/crm/activities"
            );
        };

    const confirmDelete =
        () => {
            if (
                !deletingActivity
            ) {
                return;
            }

            router.delete(
                `/erp/crm/activities/${deletingActivity.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeletingActivity(
                                null
                            ),
                }
            );
        };

    return (
        <ERPLayout>
            <Head title="CRM Activities" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Activities
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Calls, meetings,
                            emails, tasks,
                            follow-ups and
                            CRM notes.
                        </p>
                    </div>

                    {can(
                        "erp.crm-activities.create"
                    ) && (
                        <Link
                            href="/erp/crm/activities/create"
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                        >
                            <Plus className="h-4 w-4" />

                            New Activity
                        </Link>
                    )}
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />

                            <input
                                value={
                                    search
                                }
                                onChange={(
                                    event
                                ) =>
                                    setSearch(
                                        event.target
                                            .value
                                    )
                                }
                                onKeyDown={(
                                    event
                                ) => {
                                    if (
                                        event.key ===
                                        "Enter"
                                    ) {
                                        applyFilters();
                                    }
                                }}
                                placeholder="Search activities..."
                                className="h-10 w-full rounded-md border border-slate-200 pl-9 pr-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            />
                        </div>

                        <select
                            value={
                                companyId
                            }
                            onChange={(
                                event
                            ) =>
                                setCompanyId(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Companies
                            </option>

                            {companies.map(
                                (
                                    company
                                ) => (
                                    <option
                                        key={
                                            company.id
                                        }
                                        value={
                                            company.id
                                        }
                                    >
                                        {
                                            company.name
                                        }
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={
                                activityType
                            }
                            onChange={(
                                event
                            ) =>
                                setActivityType(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Types
                            </option>
                            <option value="call">
                                Call
                            </option>
                            <option value="meeting">
                                Meeting
                            </option>
                            <option value="email">
                                Email
                            </option>
                            <option value="task">
                                Task
                            </option>
                            <option value="follow_up">
                                Follow-up
                            </option>
                            <option value="note">
                                Note
                            </option>
                        </select>

                        <select
                            value={
                                status
                            }
                            onChange={(
                                event
                            ) =>
                                setStatus(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Statuses
                            </option>
                            <option value="planned">
                                Planned
                            </option>
                            <option value="in_progress">
                                In Progress
                            </option>
                            <option value="completed">
                                Completed
                            </option>
                            <option value="cancelled">
                                Cancelled
                            </option>
                        </select>

                        <select
                            value={
                                priority
                            }
                            onChange={(
                                event
                            ) =>
                                setPriority(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Priorities
                            </option>
                            <option value="low">
                                Low
                            </option>
                            <option value="normal">
                                Normal
                            </option>
                            <option value="high">
                                High
                            </option>
                            <option value="urgent">
                                Urgent
                            </option>
                        </select>

                        <select
                            value={
                                assigneeId
                            }
                            onChange={(
                                event
                            ) =>
                                setAssigneeId(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Assignees
                            </option>

                            {users.map(
                                (
                                    user
                                ) => (
                                    <option
                                        key={
                                            user.id
                                        }
                                        value={
                                            user.id
                                        }
                                    >
                                        {
                                            user.name
                                        }
                                    </option>
                                )
                            )}
                        </select>

                        <div className="flex gap-2 xl:col-span-2">
                            <button
                                type="button"
                                onClick={
                                    applyFilters
                                }
                                className="h-10 flex-1 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                            >
                                Filter
                            </button>

                            <button
                                type="button"
                                onClick={
                                    resetFilters
                                }
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm dark:border-slate-800"
                            >
                                Reset
                            </button>
                        </div>
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                                <tr>
                                    <th className="px-4 py-3">
                                        Activity
                                    </th>

                                    <th className="px-4 py-3">
                                        Lead
                                    </th>

                                    <th className="px-4 py-3">
                                        Status
                                    </th>

                                    <th className="px-4 py-3">
                                        Priority
                                    </th>

                                    <th className="px-4 py-3">
                                        Assigned
                                    </th>

                                    <th className="px-4 py-3">
                                        Schedule
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {activities.data.map(
                                    (
                                        activity
                                    ) => (
                                        <tr
                                            key={
                                                activity.id
                                            }
                                        >
                                            <td className="px-4 py-4">
                                                <div className="font-medium">
                                                    {
                                                        activity.subject
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {humanize(
                                                        activity.activity_type
                                                    )}
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {activity.activityable ? (
                                                    <Link
                                                        href={`/erp/crm/leads/${activity.activityable.id}`}
                                                        className="hover:underline"
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
                                                ) : (
                                                    "—"
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                <StatusBadge
                                                    value={
                                                        activity.status
                                                    }
                                                />
                                            </td>

                                            <td className="px-4 py-4">
                                                <PriorityBadge
                                                    value={
                                                        activity.priority
                                                    }
                                                />
                                            </td>

                                            <td className="px-4 py-4">
                                                {activity
                                                    .assigned_to
                                                    ?.name ??
                                                    "Unassigned"}
                                            </td>

                                            <td className="px-4 py-4">
                                                {activity.due_at
                                                    ? new Date(
                                                          activity.due_at
                                                      ).toLocaleString()
                                                    : activity.scheduled_at
                                                    ? new Date(
                                                          activity.scheduled_at
                                                      ).toLocaleString()
                                                    : "—"}
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.crm-activities.view"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/crm/activities/${activity.id}`}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.crm-activities.edit"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/crm/activities/${activity.id}/edit`}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                                                        >
                                                            <FilePenLine className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.crm-activities.delete"
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDeletingActivity(
                                                                    activity
                                                                )
                                                            }
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-600 dark:border-red-900 dark:text-red-400"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {activities.data
                                    .length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-4 py-16 text-center text-slate-500"
                                        >
                                            No CRM
                                            activities
                                            found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {activities.links
                        .length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-slate-800">
                            {activities.links.map(
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

            {deletingActivity && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950">
                        <Trash2 className="h-8 w-8 text-red-600" />

                        <h2 className="mt-4 text-lg font-semibold">
                            Delete Activity
                        </h2>

                        <p className="mt-2 text-sm leading-6 text-slate-500">
                            Delete{" "}
                            <strong>
                                {
                                    deletingActivity.subject
                                }
                            </strong>
                            ?
                        </p>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setDeletingActivity(
                                        null
                                    )
                                }
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm dark:border-slate-800"
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                onClick={
                                    confirmDelete
                                }
                                className="h-10 rounded-md bg-red-600 px-4 text-sm font-medium text-white"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ERPLayout>
    );
}

function StatusBadge({
    value,
}: {
    value:
        Activity["status"];
}) {
    const classes =
        value === "completed"
            ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
            : value ===
              "cancelled"
            ? "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
            : value ===
              "in_progress"
            ? "bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400"
            : "bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400";

    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${classes}`}
        >
            {humanize(
                value
            )}
        </span>
    );
}

function PriorityBadge({
    value,
}: {
    value:
        Activity["priority"];
}) {
    const classes =
        value === "urgent"
            ? "bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400"
            : value ===
              "high"
            ? "bg-orange-50 text-orange-700 dark:bg-orange-950/40 dark:text-orange-400"
            : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400";

    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${classes}`}
        >
            {humanize(
                value
            )}
        </span>
    );
}