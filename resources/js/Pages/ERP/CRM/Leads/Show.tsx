import {
    Head,
    Link,
} from "@inertiajs/react";

import {
    ArrowLeft,
    BriefcaseBusiness,
    Building2,
    CalendarClock,
    FilePenLine,
    Mail,
    MapPin,
    Phone,
    UserRound,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import type { LeadDetail } from "@/types/lead";

type Props = {
    lead: LeadDetail;
};

function money(
    value:
        string | number,
    currency: string
) {
    return `${Number(
        value ?? 0
    ).toLocaleString(
        undefined,
        {
            minimumFractionDigits:
                2,
            maximumFractionDigits:
                2,
        }
    )} ${currency}`;
}

export default function Show({
    lead,
}: Props) {
    const { can } =
        useAuthorization();

    const address = [
        lead.address_line_1,
        lead.address_line_2,
        lead.city,
        lead.state,
        lead.postal_code,
        lead.country,
    ]
        .filter(Boolean)
        .join(", ");

    return (
        <ERPLayout>
            <Head
                title={`Lead ${lead.lead_number}`}
            />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex gap-4">
                        <Link
                            href="/erp/crm/leads"
                            className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>

                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold">
                                    {lead.lead_type ===
                                    "company"
                                        ? lead.company_name
                                        : lead.contact_name}
                                </h1>

                                {lead.status && (
                                    <span
                                        className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                            lead.status
                                                .is_won
                                                ? "bg-emerald-50 text-emerald-700"
                                                : lead.status
                                                      .is_lost
                                                ? "bg-red-50 text-red-700"
                                                : "bg-blue-50 text-blue-700"
                                        }`}
                                    >
                                        {
                                            lead.status
                                                .name
                                        }
                                    </span>
                                )}
                            </div>

                            <p className="mt-1 font-mono text-sm text-slate-500">
                                {
                                    lead.lead_number
                                }
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {can("erp.leads.convert") && !lead.converted_at && (
                            <Link
                                href={`/erp/crm/leads/${lead.id}/convert`}
                                className="inline-flex h-10 items-center rounded-md border px-4 text-sm font-medium"
                            >
                                Convert Lead
                            </Link>
                        )}

                        {can("erp.opportunities.create") && lead.converted_at && (
                            <Link
                                href={`/erp/crm/opportunities/create?lead_id=${lead.id}`}
                                className="inline-flex h-10 items-center rounded-md border px-4 text-sm font-medium"
                            >
                                Create Opportunity
                            </Link>
                        )}

                        {can("erp.leads.edit") && (
                            <Link
                                href={`/erp/crm/leads/${lead.id}/edit`}
                                className="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium dark:border-slate-800"
                            >
                                <FilePenLine className="h-4 w-4" />
                                Edit Lead
                            </Link>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        label="Expected Value"
                        value={money(
                            lead.expected_value,
                            lead.currency
                        )}
                    />

                    <Metric
                        label="Probability"
                        value={`${lead.status?.probability ?? 0}%`}
                    />

                    <Metric
                        label="Source"
                        value={
                            lead.source
                                ?.name ??
                            "—"
                        }
                    />

                    <Metric
                        label="Owner"
                        value={
                            lead.owner
                                ?.name ??
                            "Unassigned"
                        }
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                        <h2 className="font-semibold">
                            Contact Information
                        </h2>

                        <div className="mt-5 space-y-4">
                            <Info
                                icon={
                                    UserRound
                                }
                                label="Contact"
                                value={
                                    lead.contact_name
                                }
                            />

                            {lead.company_name && (
                                <Info
                                    icon={
                                        Building2
                                    }
                                    label="Company"
                                    value={
                                        lead.company_name
                                    }
                                />
                            )}

                            <Info
                                icon={
                                    BriefcaseBusiness
                                }
                                label="Job Title"
                                value={
                                    lead.job_title ??
                                    "—"
                                }
                            />

                            <Info
                                icon={
                                    Mail
                                }
                                label="Email"
                                value={
                                    lead.email ??
                                    "—"
                                }
                            />

                            <Info
                                icon={
                                    Phone
                                }
                                label="Phone"
                                value={
                                    lead.phone ??
                                    "—"
                                }
                            />

                            {lead.alternate_phone && (
                                <Info
                                    icon={
                                        Phone
                                    }
                                    label="Alternate Phone"
                                    value={
                                        lead.alternate_phone
                                    }
                                />
                            )}
                        </div>
                    </section>

                    <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                        <h2 className="font-semibold">
                            CRM Information
                        </h2>

                        <div className="mt-5 space-y-4">
                            <Info
                                icon={
                                    Building2
                                }
                                label="ERP Company"
                                value={
                                    lead.company
                                        ?.name ??
                                    "—"
                                }
                            />

                            <Info
                                icon={
                                    CalendarClock
                                }
                                label="Next Follow-up"
                                value={
                                    lead.next_follow_up_at
                                        ? new Date(
                                              lead.next_follow_up_at
                                          ).toLocaleString()
                                        : "—"
                                }
                            />

                            <Info
                                icon={
                                    MapPin
                                }
                                label="Address"
                                value={
                                    address ||
                                    "—"
                                }
                            />

                            <Info
                                icon={
                                    UserRound
                                }
                                label="Record Status"
                                value={
                                    lead.is_active
                                        ? "Active"
                                        : "Inactive"
                                }
                            />
                        </div>
                    </section>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="font-semibold">
                                Activities
                            </h2>

                            <p className="mt-1 text-sm text-slate-500">
                                CRM communication,
                                tasks, meetings and
                                follow-ups for this lead.
                            </p>
                        </div>

                        {can(
                            "erp.crm-activities.create"
                        ) && (
                            <Link
                                href={`/erp/crm/activities/create?lead_id=${lead.id}`}
                                className="inline-flex h-10 items-center justify-center rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                            >
                                Add Activity
                            </Link>
                        )}
                    </div>

                    <div className="mt-6 space-y-4">
                        {lead.activities.map(
                            (
                                activity
                            ) => (
                                <div
                                    key={
                                        activity.id
                                    }
                                    className="relative border-l-2 border-slate-200 pl-5 dark:border-slate-800"
                                >
                                    <div className="absolute -left-[5px] top-1.5 h-2 w-2 rounded-full bg-slate-500" />

                                    <div className="flex flex-col gap-3 rounded-lg border border-slate-200 p-4 sm:flex-row sm:items-start sm:justify-between dark:border-slate-800">
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link
                                                    href={`/erp/crm/activities/${activity.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {
                                                        activity.subject
                                                    }
                                                </Link>

                                                <span className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-950/40 dark:text-blue-400">
                                                    {activity.activity_type
                                                        .replaceAll(
                                                            "_",
                                                            " "
                                                        )
                                                        .replace(
                                                            /\b\w/g,
                                                            (
                                                                letter
                                                            ) =>
                                                                letter.toUpperCase()
                                                        )}
                                                </span>

                                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-800">
                                                    {activity.status
                                                        .replaceAll(
                                                            "_",
                                                            " "
                                                        )
                                                        .replace(
                                                            /\b\w/g,
                                                            (
                                                                letter
                                                            ) =>
                                                                letter.toUpperCase()
                                                        )}
                                                </span>
                                            </div>

                                            {activity.description && (
                                                <p className="mt-2 line-clamp-3 text-sm leading-6 text-slate-500">
                                                    {
                                                        activity.description
                                                    }
                                                </p>
                                            )}

                                            <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-500">
                                                {activity.assigned_to && (
                                                    <span>
                                                        Assigned:{" "}
                                                        {
                                                            activity
                                                                .assigned_to
                                                                .name
                                                        }
                                                    </span>
                                                )}

                                                {activity.due_at && (
                                                    <span>
                                                        Due:{" "}
                                                        {new Date(
                                                            activity.due_at
                                                        ).toLocaleString()}
                                                    </span>
                                                )}

                                                <span>
                                                    Added:{" "}
                                                    {new Date(
                                                        activity.created_at
                                                    ).toLocaleString()}
                                                </span>
                                            </div>
                                        </div>

                                        {can(
                                            "erp.crm-activities.edit"
                                        ) && (
                                            <Link
                                                href={`/erp/crm/activities/${activity.id}/edit`}
                                                className="shrink-0 text-sm font-medium text-slate-600 hover:underline dark:text-slate-300"
                                            >
                                                Edit
                                            </Link>
                                        )}
                                    </div>
                                </div>
                            )
                        )}

                        {lead.activities.length ===
                            0 && (
                            <div className="rounded-lg border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700">
                                No activities have
                                been recorded for
                                this lead yet.
                            </div>
                        )}
                    </div>
                </section>

                {lead.notes && (
                    <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                        <h2 className="font-semibold">
                            Notes
                        </h2>

                        <p className="mt-4 whitespace-pre-line text-sm leading-6 text-slate-600 dark:text-slate-400">
                            {
                                lead.notes
                            }
                        </p>
                    </section>
                )}
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