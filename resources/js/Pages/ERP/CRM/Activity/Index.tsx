import {
    Head,
    Link,
} from "@inertiajs/react";

import {
    ArrowLeft,
    FileText,
    History,
    ShieldCheck,
    UserRound,
} from "lucide-react";

import type {
    ReactNode,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

type Actor = {
    id: number;
    name: string;
    email?: string | null;
};

type Change = {
    old:
        | string
        | number
        | boolean
        | null;
    new:
        | string
        | number
        | boolean
        | null;
};

type Activity = {
    id: number;
    source: string;
    action: string;
    description?: string | null;
    changes?:
        | Record<string, Change>
        | null;
    meta?:
        | Record<string, unknown>
        | null;
    ip_address?: string | null;
    created_at: string;
    actor?: Actor | null;
};

type PaginationLink = {
    url?: string | null;
    label: string;
    active: boolean;
};

type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
};

type Subject = {
    type: "Depot" | "Dealer";
    id: number;
    name: string;
    code?: string | null;
    back_url: string;
};

type Props = {
    subject: Subject;
    activities:
        Paginated<Activity>;
};

const FIELD_LABELS:
    Record<string, string> = {
        company_id:
            "Company",
        division_id:
            "Division",
        district_id:
            "District",
        depot_id:
            "Depot",
        upazila_id:
            "Upazila",
        name:
            "Name",
        business_name:
            "Business Name",
        proprietor_name:
            "Proprietor Name",
        proprietor_photo:
            "Proprietor Picture",
        phone:
            "Phone",
        alternate_phone:
            "Alternate Phone",
        email:
            "Email",
        trade_license_number:
            "Trade License Number",
        nid_number:
            "NID Number",
        tin_number:
            "TIN Number",
        vat_number:
            "VAT / BIN Number",
        address:
            "Address",
        postal_code:
            "Postal Code",
        credit_limit:
            "Credit Limit",
        payment_terms_days:
            "Payment Terms",
        manager_user_id:
            "Manager",
        is_active:
            "Status",
        notes:
            "Notes",
    };

const ACTION_LABELS:
    Record<string, string> = {
        "profile.updated":
            "Profile Updated",

        "document.uploaded":
            "Document Uploaded",

        "document.verified":
            "Document Verified",

        "document.unverified":
            "Document Unverified",

        "document.removed":
            "Document Removed",
    };

export default function Index({
    subject,
    activities,
}: Props) {
    return (
        <ERPLayout>
            <Head
                title={`${subject.type} Activity - ${subject.name}`}
            />

            <div className="space-y-6">
                <div>
                    <Link
                        href={
                            subject.back_url
                        }
                        className="mb-3 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-950 dark:hover:text-white"
                    >
                        <ArrowLeft className="h-4 w-4" />

                        Back to {subject.type}
                    </Link>

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-lg border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-950">
                                    <History className="h-5 w-5" />
                                </div>

                                <div>
                                    <h1 className="text-2xl font-semibold">
                                        Activity History
                                    </h1>

                                    <p className="mt-1 text-sm text-neutral-500">
                                        {subject.name}
                                        {subject.code
                                            ? ` · ${subject.code}`
                                            : ""}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-lg border border-neutral-200 bg-white px-4 py-3 text-sm dark:border-neutral-800 dark:bg-neutral-950">
                            <span className="text-neutral-500">
                                Total activities
                            </span>

                            <span className="ml-2 font-semibold">
                                {activities.total}
                            </span>
                        </div>
                    </div>
                </div>

                <div className="space-y-4">
                    {activities.data.map(
                        (activity) => (
                            <ActivityCard
                                key={
                                    activity.id
                                }
                                activity={
                                    activity
                                }
                            />
                        )
                    )}

                    {activities.data.length ===
                        0 && (
                        <div className="rounded-xl border border-dashed border-neutral-300 bg-white px-6 py-16 text-center dark:border-neutral-700 dark:bg-neutral-950">
                            <History className="mx-auto h-8 w-8 text-neutral-400" />

                            <p className="mt-3 font-medium">
                                No activity history yet
                            </p>

                            <p className="mt-1 text-sm text-neutral-500">
                                Future profile and document changes will appear here.
                            </p>
                        </div>
                    )}
                </div>

                {activities.links.length >
                    3 && (
                    <div className="flex flex-wrap gap-1">
                        {activities.links.map(
                            (
                                link,
                                index
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
                                        className={`rounded-md px-3 py-2 text-sm ${
                                            link.active
                                                ? "bg-neutral-950 text-white dark:bg-white dark:text-neutral-950"
                                                : "border border-neutral-200 dark:border-neutral-800"
                                        }`}
                                        dangerouslySetInnerHTML={{
                                            __html:
                                                link.label,
                                        }}
                                    />
                                ) : (
                                    <span
                                        key={
                                            index
                                        }
                                        className="rounded-md border border-neutral-200 px-3 py-2 text-sm opacity-40 dark:border-neutral-800"
                                        dangerouslySetInnerHTML={{
                                            __html:
                                                link.label,
                                        }}
                                    />
                                )
                        )}
                    </div>
                )}
            </div>
        </ERPLayout>
    );
}

function ActivityCard({
    activity,
}: {
    activity: Activity;
}) {
    const changes =
        Object.entries(
            activity.changes ??
                {}
        );

    const documentMeta =
        activity.meta ?? {};

    const isDocumentAction =
        activity.action.startsWith(
            "document."
        );

    return (
        <article className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-950">
            <div className="flex flex-col gap-4 border-b border-neutral-100 p-5 sm:flex-row sm:items-start sm:justify-between dark:border-neutral-800">
                <div className="flex gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-900">
                        {isDocumentAction ? (
                            <FileText className="h-5 w-5" />
                        ) : (
                            <ShieldCheck className="h-5 w-5" />
                        )}
                    </div>

                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-semibold">
                                {ACTION_LABELS[
                                    activity.action
                                ] ??
                                    humanize(
                                        activity.action
                                    )}
                            </h2>

                            <SourceBadge
                                source={
                                    activity.source
                                }
                            />
                        </div>

                        {activity.description && (
                            <p className="mt-1 text-sm text-neutral-500">
                                {
                                    activity.description
                                }
                            </p>
                        )}
                    </div>
                </div>

                <div className="text-left sm:text-right">
                    <p className="text-sm font-medium">
                        {formatDateTime(
                            activity.created_at
                        )}
                    </p>

                    <div className="mt-1 flex items-center gap-1 text-xs text-neutral-500 sm:justify-end">
                        <UserRound className="h-3.5 w-3.5" />

                        {activity.actor
                            ?.name ??
                            "System"}
                    </div>
                </div>
            </div>

            {changes.length >
                0 && (
                <div className="p-5">
                    <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-400">
                        Changed fields
                    </p>

                    <div className="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-800">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/50">
                                    <tr>
                                        <th className="px-4 py-3">
                                            Field
                                        </th>

                                        <th className="px-4 py-3">
                                            Previous
                                        </th>

                                        <th className="px-4 py-3">
                                            New
                                        </th>
                                    </tr>
                                </thead>

                                <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                    {changes.map(
                                        ([
                                            field,
                                            change,
                                        ]) => (
                                            <tr
                                                key={
                                                    field
                                                }
                                            >
                                                <td className="px-4 py-3 font-medium">
                                                    {FIELD_LABELS[
                                                        field
                                                    ] ??
                                                        humanize(
                                                            field
                                                        )}
                                                </td>

                                                <td className="max-w-xs whitespace-pre-wrap break-words px-4 py-3 text-neutral-500">
                                                    {displayValue(
                                                        change.old
                                                    )}
                                                </td>

                                                <td className="max-w-xs whitespace-pre-wrap break-words px-4 py-3">
                                                    {displayValue(
                                                        change.new
                                                    )}
                                                </td>
                                            </tr>
                                        )
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}

            {isDocumentAction &&
                Object.keys(
                    documentMeta
                ).length >
                    0 && (
                <div className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <MetaItem
                        label="Document Type"
                        value={
                            documentMeta[
                                "document_type"
                            ]
                        }
                    />

                    <MetaItem
                        label="Version"
                        value={
                            documentMeta[
                                "version"
                            ]
                        }
                    />

                    <MetaItem
                        label="File"
                        value={
                            documentMeta[
                                "original_name"
                            ]
                        }
                    />

                    <MetaItem
                        label="Document ID"
                        value={
                            documentMeta[
                                "document_id"
                            ]
                        }
                    />
                </div>
            )}

            <div className="flex flex-wrap gap-x-6 gap-y-2 border-t border-neutral-100 px-5 py-3 text-xs text-neutral-500 dark:border-neutral-800">
                <span>
                    Actor:{" "}
                    {activity.actor
                        ?.name ??
                        "System"}
                </span>

                {activity.actor
                    ?.email && (
                    <span>
                        {
                            activity.actor
                                .email
                        }
                    </span>
                )}

                {activity.ip_address && (
                    <span>
                        IP:{" "}
                        {
                            activity.ip_address
                        }
                    </span>
                )}
            </div>
        </article>
    );
}

function SourceBadge({
    source,
}: {
    source: string;
}) {
    return (
        <span className="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium capitalize text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
            {source.replaceAll(
                "_",
                " "
            )}
        </span>
    );
}

function MetaItem({
    label,
    value,
}: {
    label: string;
    value: unknown;
}) {
    return (
        <div>
            <p className="text-xs font-medium uppercase tracking-wide text-neutral-400">
                {label}
            </p>

            <p className="mt-1 break-words text-sm">
                {value === null ||
                value === undefined ||
                value === ""
                    ? "—"
                    : String(
                          value
                      )}
            </p>
        </div>
    );
}

function displayValue(
    value:
        | string
        | number
        | boolean
        | null
) {
    if (
        value === null ||
        value === ""
    ) {
        return "—";
    }

    if (
        typeof value ===
        "boolean"
    ) {
        return value
            ? "Yes"
            : "No";
    }

    return String(
        value
    );
}

function humanize(
    value: string
) {
    return value
        .replaceAll(
            ".",
            " "
        )
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

function formatDateTime(
    value: string
) {
    return new Date(
        value
    ).toLocaleString();
}
