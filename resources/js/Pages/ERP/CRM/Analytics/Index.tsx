import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    CalendarClock,
    ChartNoAxesCombined,
    CircleDollarSign,
    Filter,
    Target,
    TrendingUp,
    Trophy,
    UserCheck,
    Users,
    XCircle,
} from "lucide-react";

import {
    useState,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

type Metrics = {
    new_leads: number;
    qualified_leads: number;
    converted_leads: number;
    conversion_rate: number;
    open_pipeline_count: number;
    open_pipeline_value: number;
    weighted_pipeline_value: number;
    won_count: number;
    won_value: number;
    lost_count: number;
};

type StageRow = {
    id: number;
    company_id: number;
    name: string;
    probability: number;
    is_won: boolean;
    is_lost: boolean;
    count: number;
    value: number;
};

type SalespersonRow = {
    id: number;
    name: string;
    email: string;
    open_count: number;
    open_value: number;
    won_count: number;
    won_value: number;
    lost_count: number;
};

type UpcomingOpportunity = {
    id: number;
    title: string;
    expected_value:
        string | number;
    currency: string;
    probability: number;
    expected_close_date:
        string | null;

    company?:
        {
            id: number;
            name: string;
        } | null;

    stage?:
        {
            id: number;
            name: string;
        } | null;

    salesperson?:
        {
            id: number;
            name: string;
            email: string;
        } | null;

    party?:
        {
            id: number;
            name?: string;
            business_name?: string | null;
        } | null;
};

type Props = {
    metrics: Metrics;

    stageFunnel:
        StageRow[];

    salespersonPerformance:
        SalespersonRow[];

    upcomingCloses:
        UpcomingOpportunity[];

    companies:
        {
            id: number;
            name: string;
        }[];

    filters: {
        company_id: string;
        from: string;
        to: string;
    };
};

function money(
    value:
        string | number
): string {
    return Number(
        value || 0
    ).toLocaleString(
        undefined,
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }
    );
}

export default function Index({
    metrics,
    stageFunnel,
    salespersonPerformance,
    upcomingCloses,
    companies,
    filters,
}: Props) {
    const [
        companyId,
        setCompanyId,
    ] =
        useState(
            filters.company_id
        );

    const [
        from,
        setFrom,
    ] =
        useState(
            filters.from
        );

    const [
        to,
        setTo,
    ] =
        useState(
            filters.to
        );

    const applyFilters =
        () => {
            router.get(
                "/erp/crm/analytics",
                {
                    company_id:
                        companyId ||
                        undefined,

                    from:
                        from ||
                        undefined,

                    to:
                        to ||
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

    return (
        <ERPLayout>
            <Head title="CRM Analytics" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm font-medium text-slate-500">
                            <ChartNoAxesCombined className="h-4 w-4" />
                            Phase 2 CRM
                        </div>

                        <h1 className="mt-2 text-2xl font-semibold">
                            CRM Analytics
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Lead conversion,
                            pipeline health,
                            sales performance
                            and expected closes.
                        </p>
                    </div>

                    <Link
                        href="/erp/crm/opportunities"
                        className="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 px-4 text-sm font-medium dark:border-slate-800"
                    >
                        View Opportunities
                    </Link>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_180px_180px_auto]">
                        <select
                            value={
                                companyId
                            }
                            onChange={(
                                event
                            ) =>
                                setCompanyId(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
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

                        <input
                            type="date"
                            value={
                                from
                            }
                            onChange={(
                                event
                            ) =>
                                setFrom(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        />

                        <input
                            type="date"
                            value={
                                to
                            }
                            onChange={(
                                event
                            ) =>
                                setTo(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        />

                        <button
                            type="button"
                            onClick={
                                applyFilters
                            }
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                        >
                            <Filter className="h-4 w-4" />
                            Apply
                        </button>
                    </div>
                </section>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <MetricCard
                        label="New Leads"
                        value={String(
                            metrics.new_leads
                        )}
                        icon={
                            Users
                        }
                    />

                    <MetricCard
                        label="Qualified Leads"
                        value={String(
                            metrics.qualified_leads
                        )}
                        icon={
                            UserCheck
                        }
                    />

                    <MetricCard
                        label="Converted Leads"
                        value={String(
                            metrics.converted_leads
                        )}
                        icon={
                            Target
                        }
                    />

                    <MetricCard
                        label="Conversion Rate"
                        value={`${metrics.conversion_rate}%`}
                        icon={
                            TrendingUp
                        }
                    />

                    <MetricCard
                        label="Open Opportunities"
                        value={String(
                            metrics.open_pipeline_count
                        )}
                        icon={
                            ChartNoAxesCombined
                        }
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <MetricCard
                        label="Open Pipeline"
                        value={money(
                            metrics.open_pipeline_value
                        )}
                        icon={
                            CircleDollarSign
                        }
                    />

                    <MetricCard
                        label="Weighted Pipeline"
                        value={money(
                            metrics.weighted_pipeline_value
                        )}
                        icon={
                            TrendingUp
                        }
                    />

                    <MetricCard
                        label="Won Deals"
                        value={String(
                            metrics.won_count
                        )}
                        icon={
                            Trophy
                        }
                    />

                    <MetricCard
                        label="Won Value"
                        value={money(
                            metrics.won_value
                        )}
                        icon={
                            CircleDollarSign
                        }
                    />

                    <MetricCard
                        label="Lost Deals"
                        value={String(
                            metrics.lost_count
                        )}
                        icon={
                            XCircle
                        }
                    />
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                        <div>
                            <h2 className="font-semibold">
                                Pipeline Funnel
                            </h2>

                            <p className="mt-1 text-sm text-slate-500">
                                Current opportunities
                                by pipeline stage.
                            </p>
                        </div>

                        <div className="mt-6 space-y-4">
                            {stageFunnel.map(
                                (
                                    stage
                                ) => (
                                    <div
                                        key={
                                            stage.id
                                        }
                                    >
                                        <div className="flex items-center justify-between gap-4 text-sm">
                                            <div>
                                                <span className="font-medium">
                                                    {
                                                        stage.name
                                                    }
                                                </span>

                                                <span className="ml-2 text-xs text-slate-500">
                                                    {
                                                        stage.probability
                                                    }
                                                    %
                                                </span>
                                            </div>

                                            <div className="text-right">
                                                <div className="font-medium">
                                                    {
                                                        stage.count
                                                    }{" "}
                                                    deals
                                                </div>

                                                <div className="text-xs text-slate-500">
                                                    {money(
                                                        stage.value
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-900">
                                            <div
                                                className="h-full rounded-full bg-slate-700 dark:bg-slate-300"
                                                style={{
                                                    width:
                                                        `${Math.max(
                                                            4,
                                                            funnelWidth(
                                                                stage.count,
                                                                stageFunnel
                                                            )
                                                        )}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                )
                            )}

                            {stageFunnel.length ===
                                0 && (
                                <EmptyState text="No opportunity stages available." />
                            )}
                        </div>
                    </section>

                    <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                        <div>
                            <h2 className="font-semibold">
                                Upcoming Expected Closes
                            </h2>

                            <p className="mt-1 text-sm text-slate-500">
                                Next open opportunities
                                ordered by expected
                                close date.
                            </p>
                        </div>

                        <div className="mt-6 divide-y divide-slate-100 dark:divide-slate-800">
                            {upcomingCloses.map(
                                (
                                    opportunity
                                ) => (
                                    <Link
                                        key={
                                            opportunity.id
                                        }
                                        href={`/erp/crm/opportunities/${opportunity.id}`}
                                        className="flex items-start justify-between gap-4 py-4 first:pt-0 last:pb-0"
                                    >
                                        <div>
                                            <div className="font-medium hover:underline">
                                                {
                                                    opportunity.title
                                                }
                                            </div>

                                            <div className="mt-1 text-xs text-slate-500">
                                                {
                                                    opportunity
                                                        .salesperson
                                                        ?.name ??
                                                    "Unassigned"
                                                }{" "}
                                                ·{" "}
                                                {
                                                    opportunity
                                                        .stage
                                                        ?.name ??
                                                    "—"
                                                }
                                            </div>
                                        </div>

                                        <div className="shrink-0 text-right">
                                            <div className="text-sm font-medium">
                                                {
                                                    opportunity.currency
                                                }{" "}
                                                {money(
                                                    opportunity.expected_value
                                                )}
                                            </div>

                                            <div className="mt-1 flex items-center justify-end gap-1 text-xs text-slate-500">
                                                <CalendarClock className="h-3.5 w-3.5" />

                                                {opportunity.expected_close_date
                                                    ? new Date(
                                                          opportunity.expected_close_date
                                                      ).toLocaleDateString()
                                                    : "—"}
                                            </div>
                                        </div>
                                    </Link>
                                )
                            )}

                            {upcomingCloses.length ===
                                0 && (
                                <EmptyState text="No upcoming expected closes." />
                            )}
                        </div>
                    </section>
                </div>

                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="border-b border-slate-200 p-6 dark:border-slate-800">
                        <h2 className="font-semibold">
                            Salesperson Performance
                        </h2>

                        <p className="mt-1 text-sm text-slate-500">
                            Open pipeline,
                            won value and
                            lost deals for
                            the selected period.
                        </p>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[900px] text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                                <tr>
                                    <th className="px-6 py-3">
                                        Salesperson
                                    </th>

                                    <th className="px-6 py-3">
                                        Open
                                    </th>

                                    <th className="px-6 py-3">
                                        Open Value
                                    </th>

                                    <th className="px-6 py-3">
                                        Won
                                    </th>

                                    <th className="px-6 py-3">
                                        Won Value
                                    </th>

                                    <th className="px-6 py-3">
                                        Lost
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {salespersonPerformance.map(
                                    (
                                        row
                                    ) => (
                                        <tr
                                            key={
                                                row.id
                                            }
                                        >
                                            <td className="px-6 py-4">
                                                <div className="font-medium">
                                                    {
                                                        row.name
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {
                                                        row.email
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-6 py-4">
                                                {
                                                    row.open_count
                                                }
                                            </td>

                                            <td className="px-6 py-4">
                                                {money(
                                                    row.open_value
                                                )}
                                            </td>

                                            <td className="px-6 py-4">
                                                {
                                                    row.won_count
                                                }
                                            </td>

                                            <td className="px-6 py-4 font-medium">
                                                {money(
                                                    row.won_value
                                                )}
                                            </td>

                                            <td className="px-6 py-4">
                                                {
                                                    row.lost_count
                                                }
                                            </td>
                                        </tr>
                                    )
                                )}

                                {salespersonPerformance.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                6
                                            }
                                            className="px-6 py-12 text-center text-sm text-slate-500"
                                        >
                                            No salesperson
                                            activity for
                                            this period.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </ERPLayout>
    );
}

function funnelWidth(
    count: number,
    rows:
        StageRow[]
): number {
    const max =
        Math.max(
            1,
            ...rows.map(
                (
                    row
                ) =>
                    row.count
            )
        );

    return (
        count /
        max
    ) * 100;
}

function MetricCard({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: string;
    icon:
        typeof Users;
}) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
            <div className="flex items-center justify-between gap-3">
                <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                    {label}
                </div>

                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-900">
                    <Icon className="h-4 w-4" />
                </div>
            </div>

            <div className="mt-4 text-2xl font-semibold">
                {value}
            </div>
        </div>
    );
}

function EmptyState({
    text,
}: {
    text: string;
}) {
    return (
        <div className="rounded-lg border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700">
            {text}
        </div>
    );
}
