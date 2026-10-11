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

type Source = {
    id: number;
    company_id?: number;
    name: string;
    code: string;
};

type Status = {
    id: number;
    company_id?: number;
    name: string;
    code: string;
    probability: number;
    is_won: boolean;
    is_lost: boolean;
};

type UserOption = {
    id: number;
    name: string;
    email: string;
};

import type { Lead } from "@/types/lead";

type PaginationLink = {
    url:
        string | null;

    label:
        string;

    active:
        boolean;
};

type Props = {
    leads: {
        data: Lead[];
        links:
            PaginationLink[];
        total: number;
    };

    companies:
        Company[];

    sources:
        (Source & {
            company_id:
                number;
        })[];

    statuses:
        (Status & {
            company_id:
                number;
        })[];

    users:
        UserOption[];

    filters: {
        search?: string;
        company_id?:
            number | string;
        lead_source_id?:
            number | string;
        lead_status_id?:
            number | string;
        owner_user_id?:
            number | string;
        lead_type?: string;
        active?: string;
    };
};

function money(
    value:
        number | string,
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

export default function Index({
    leads,
    companies,
    sources,
    statuses,
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
        sourceId,
        setSourceId,
    ] = useState(
        filters.lead_source_id
            ? String(
                  filters.lead_source_id
              )
            : ""
    );

    const [
        statusId,
        setStatusId,
    ] = useState(
        filters.lead_status_id
            ? String(
                  filters.lead_status_id
              )
            : ""
    );

    const [
        ownerId,
        setOwnerId,
    ] = useState(
        filters.owner_user_id
            ? String(
                  filters.owner_user_id
              )
            : ""
    );

    const [
        leadType,
        setLeadType,
    ] = useState(
        filters.lead_type ??
            ""
    );

    const [
        active,
        setActive,
    ] = useState(
        filters.active ??
            ""
    );

    const [
        deletingLead,
        setDeletingLead,
    ] =
        useState<Lead | null>(
            null
        );

    const companySources =
        sources.filter(
            (source) =>
                !companyId ||
                String(
                    source.company_id
                ) ===
                    companyId
        );

    const companyStatuses =
        statuses.filter(
            (status) =>
                !companyId ||
                String(
                    status.company_id
                ) ===
                    companyId
        );

    const applyFilters =
        () => {
            router.get(
                "/erp/crm/leads",
                {
                    search:
                        search ||
                        undefined,

                    company_id:
                        companyId ||
                        undefined,

                    lead_source_id:
                        sourceId ||
                        undefined,

                    lead_status_id:
                        statusId ||
                        undefined,

                    owner_user_id:
                        ownerId ||
                        undefined,

                    lead_type:
                        leadType ||
                        undefined,

                    active:
                        active ||
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
            setSourceId("");
            setStatusId("");
            setOwnerId("");
            setLeadType("");
            setActive("");

            router.get(
                "/erp/crm/leads"
            );
        };

    const confirmDelete =
        () => {
            if (!deletingLead) {
                return;
            }

            router.delete(
                `/erp/crm/leads/${deletingLead.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeletingLead(
                                null
                            ),
                }
            );
        };

    return (
        <ERPLayout>
            <Head title="Leads" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Leads
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Manage CRM
                            prospects and
                            pipeline progress.
                        </p>
                    </div>

                    {can(
                        "erp.leads.create"
                    ) && (
                        <Link
                            href="/erp/crm/leads/create"
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                        >
                            <Plus className="h-4 w-4" />

                            New Lead
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
                                placeholder="Lead, name, email, phone..."
                                className="h-10 w-full rounded-md border border-slate-200 pl-9 pr-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            />
                        </div>

                        <select
                            value={
                                companyId
                            }
                            onChange={(
                                event
                            ) => {
                                setCompanyId(
                                    event.target
                                        .value
                                );

                                setSourceId(
                                    ""
                                );

                                setStatusId(
                                    ""
                                );
                            }}
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Companies
                            </option>

                            {companies.map(
                                (company) => (
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
                                sourceId
                            }
                            onChange={(
                                event
                            ) =>
                                setSourceId(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Sources
                            </option>

                            {companySources.map(
                                (source) => (
                                    <option
                                        key={
                                            source.id
                                        }
                                        value={
                                            source.id
                                        }
                                    >
                                        {
                                            source.name
                                        }
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={
                                statusId
                            }
                            onChange={(
                                event
                            ) =>
                                setStatusId(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Pipeline Statuses
                            </option>

                            {companyStatuses.map(
                                (status) => (
                                    <option
                                        key={
                                            status.id
                                        }
                                        value={
                                            status.id
                                        }
                                    >
                                        {
                                            status.name
                                        }
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={
                                ownerId
                            }
                            onChange={(
                                event
                            ) =>
                                setOwnerId(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Owners
                            </option>

                            {users.map(
                                (user) => (
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

                        <select
                            value={
                                leadType
                            }
                            onChange={(
                                event
                            ) =>
                                setLeadType(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Types
                            </option>

                            <option value="individual">
                                Individual
                            </option>

                            <option value="company">
                                Company
                            </option>
                        </select>

                        <select
                            value={active}
                            onChange={(
                                event
                            ) =>
                                setActive(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                Active & Inactive
                            </option>

                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>

                        <div className="flex gap-2">
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
                                        Lead
                                    </th>

                                    <th className="px-4 py-3">
                                        Status
                                    </th>

                                    <th className="px-4 py-3">
                                        Source
                                    </th>

                                    <th className="px-4 py-3">
                                        Owner
                                    </th>

                                    <th className="px-4 py-3">
                                        Value
                                    </th>

                                    <th className="px-4 py-3">
                                        Follow-up
                                    </th>

                                    <th className="px-4 py-3">
                                        Active
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {leads.data.map(
                                    (lead) => (
                                        <tr
                                            key={
                                                lead.id
                                            }
                                        >
                                            <td className="px-4 py-4">
                                                <Link
                                                    href={`/erp/crm/leads/${lead.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {lead.lead_type ===
                                                        "company"
                                                        ? lead.company_name ||
                                                          lead.contact_name
                                                        : lead.contact_name}
                                                </Link>

                                                <div className="mt-1 font-mono text-xs text-slate-500">
                                                    {
                                                        lead.lead_number
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {lead.email ??
                                                        lead.phone ??
                                                        "—"}
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {lead.status ? (
                                                    <div>
                                                        <span
                                                            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                                lead.status
                                                                    .is_won
                                                                    ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                                                    : lead
                                                                          .status
                                                                          .is_lost
                                                                    ? "bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400"
                                                                    : "bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400"
                                                            }`}
                                                        >
                                                            {
                                                                lead.status
                                                                    .name
                                                            }
                                                        </span>

                                                        <div className="mt-1 text-xs text-slate-500">
                                                            {
                                                                lead.status
                                                                    .probability
                                                            }
                                                            %
                                                        </div>
                                                    </div>
                                                ) : (
                                                    "—"
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                {lead.source
                                                    ?.name ??
                                                    "—"}
                                            </td>

                                            <td className="px-4 py-4">
                                                {lead.owner
                                                    ?.name ??
                                                    "Unassigned"}
                                            </td>

                                            <td className="px-4 py-4 font-medium">
                                                {money(
                                                    lead.expected_value,
                                                    lead.currency
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                {lead.next_follow_up_at
                                                    ? new Date(
                                                          lead.next_follow_up_at
                                                      ).toLocaleString()
                                                    : "—"}
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                        lead.is_active
                                                            ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                                            : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
                                                    }`}
                                                >
                                                    {lead.is_active
                                                        ? "Active"
                                                        : "Inactive"}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.leads.view"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/crm/leads/${lead.id}`}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.leads.edit"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/crm/leads/${lead.id}/edit`}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                                                        >
                                                            <FilePenLine className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.leads.delete"
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDeletingLead(
                                                                    lead
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

                                {leads.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                8
                                            }
                                            className="px-4 py-16 text-center text-slate-500"
                                        >
                                            No leads
                                            found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {leads.links.length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-slate-800">
                            {leads.links.map(
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

            {deletingLead && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950">
                        <Trash2 className="h-8 w-8 text-red-600" />

                        <h2 className="mt-4 text-lg font-semibold">
                            Delete Lead
                        </h2>

                        <p className="mt-2 text-sm leading-6 text-slate-500">
                            Delete{" "}
                            <strong>
                                {
                                    deletingLead.contact_name
                                }
                            </strong>
                            ? The lead will
                            be soft deleted
                            from CRM.
                        </p>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setDeletingLead(
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