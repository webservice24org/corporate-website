import {
    Head,
    Link,
} from "@inertiajs/react";

import type {
    ComponentType,
    ReactNode,
} from "react";

import {
    ArrowLeft,
    CalendarDays,
    FilePenLine,
    Landmark,
    UserRound,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";
import {
    useAuthorization,
} from "@/hooks/useAuthorization";

type ComponentReference = {
    id: number;
    name: string;
    code: string;
};

type SalaryItem = {
    id: number;

    component_name:
        string;

    component_code:
        string;

    component_type:
        | "earning"
        | "deduction";

    calculation_type:
        | "fixed"
        | "percentage";

    amount:
        string | number;

    percentage:
        string | number;

    calculated_amount:
        string | number;

    is_taxable:
        boolean;

    affects_gross_salary:
        boolean;

    is_recurring:
        boolean;

    percentage_of_component?:
        ComponentReference | null;
};

type Employee = {
    id: number;

    employee_number:
        string;

    first_name:
        string;

    middle_name?:
        string | null;

    last_name?:
        string | null;

    branch?: {
        name: string;
    } | null;

    department?: {
        name: string;
    } | null;

    designation?: {
        name: string;
    } | null;
};

type SalaryStructure = {
    id: number;

    effective_from:
        string;

    effective_to:
        string | null;

    status:
        | "active"
        | "inactive";

    currency:
        string;

    gross_salary:
        string | number;

    total_earnings:
        string | number;

    total_deductions:
        string | number;

    net_salary:
        string | number;

    notes:
        string | null;

    company?: {
        id: number;
        name: string;
    } | null;

    employee?:
        Employee | null;

    items:
        SalaryItem[];
};

type Props = {
    salaryStructure:
        SalaryStructure;
};

function employeeName(
    employee:
        Employee | null | undefined
): string {
    if (!employee) {
        return "—";
    }

    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

function money(
    value:
        | string
        | number,
    currency:
        string
): string {
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
    salaryStructure,
}: Props) {
    const { can } =
        useAuthorization();

    const earnings =
        salaryStructure.items.filter(
            (item) =>
                item.component_type ===
                "earning"
        );

    const deductions =
        salaryStructure.items.filter(
            (item) =>
                item.component_type ===
                "deduction"
        );

    return (
        <ERPLayout>
            <Head title="Salary Structure" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-start gap-4">
                        <Link
                            href="/erp/payroll/salary-structures"
                            className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>

                        <div>
                            <div className="flex flex-wrap items-center gap-3">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    Salary
                                    Structure
                                </h1>

                                <span
                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                        salaryStructure.status ===
                                        "active"
                                            ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                            : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
                                    }`}
                                >
                                    {
                                        salaryStructure.status
                                    }
                                </span>
                            </div>

                            <p className="mt-1 text-sm text-slate-500">
                                {employeeName(
                                    salaryStructure.employee
                                )}{" "}
                                ·{" "}
                                {salaryStructure
                                    .employee
                                    ?.employee_number ??
                                    "—"}
                            </p>
                        </div>
                    </div>

                    {can(
                        "erp.salary-structures.edit"
                    ) && (
                        <Link
                            href={`/erp/payroll/salary-structures/${salaryStructure.id}/edit`}
                            className="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                        >
                            <FilePenLine className="h-4 w-4" />

                            Edit
                            Structure
                        </Link>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <InfoCard
                        icon={
                            UserRound
                        }
                        title="Employee"
                    >
                        <div className="font-medium">
                            {employeeName(
                                salaryStructure.employee
                            )}
                        </div>

                        <div className="mt-1 text-sm text-slate-500">
                            {salaryStructure
                                .employee
                                ?.employee_number ??
                                "—"}
                        </div>

                        <div className="mt-3 text-xs leading-5 text-slate-500">
                            {[
                                salaryStructure
                                    .employee
                                    ?.department
                                    ?.name,
                                salaryStructure
                                    .employee
                                    ?.designation
                                    ?.name,
                            ]
                                .filter(
                                    Boolean
                                )
                                .join(
                                    " · "
                                ) ||
                                "—"}
                        </div>
                    </InfoCard>

                    <InfoCard
                        icon={
                            Landmark
                        }
                        title="Company"
                    >
                        <div className="font-medium">
                            {salaryStructure
                                .company
                                ?.name ??
                                "—"}
                        </div>

                        <div className="mt-2 text-sm text-slate-500">
                            Currency:{" "}
                            {
                                salaryStructure.currency
                            }
                        </div>
                    </InfoCard>

                    <InfoCard
                        icon={
                            CalendarDays
                        }
                        title="Effective Period"
                    >
                        <div className="font-medium">
                            {
                                salaryStructure.effective_from
                            }
                        </div>

                        <div className="mt-1 text-sm text-slate-500">
                            to{" "}
                            {salaryStructure.effective_to ??
                                "Current"}
                        </div>
                    </InfoCard>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        label="Total Earnings"
                        value={money(
                            salaryStructure.total_earnings,
                            salaryStructure.currency
                        )}
                    />

                    <Metric
                        label="Gross Salary"
                        value={money(
                            salaryStructure.gross_salary,
                            salaryStructure.currency
                        )}
                    />

                    <Metric
                        label="Deductions"
                        value={money(
                            salaryStructure.total_deductions,
                            salaryStructure.currency
                        )}
                    />

                    <Metric
                        label="Net Salary"
                        value={money(
                            salaryStructure.net_salary,
                            salaryStructure.currency
                        )}
                        emphasis
                    />
                </div>

                <SalaryTable
                    title="Earnings"
                    items={earnings}
                    currency={
                        salaryStructure.currency
                    }
                />

                <SalaryTable
                    title="Deductions"
                    items={
                        deductions
                    }
                    currency={
                        salaryStructure.currency
                    }
                />

                {salaryStructure.notes && (
                    <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                        <h2 className="font-semibold">
                            Notes
                        </h2>

                        <p className="mt-3 whitespace-pre-line text-sm leading-6 text-slate-600 dark:text-slate-400">
                            {
                                salaryStructure.notes
                            }
                        </p>
                    </section>
                )}
            </div>
        </ERPLayout>
    );
}

function SalaryTable({
    title,
    items,
    currency,
}: {
    title: string;
    items: SalaryItem[];
    currency: string;
}) {
    return (
        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <div className="border-b border-slate-200 px-6 py-4 dark:border-slate-800">
                <h2 className="font-semibold">
                    {title}
                </h2>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-900/50">
                        <tr>
                            <th className="px-5 py-3">
                                Component
                            </th>

                            <th className="px-5 py-3">
                                Calculation
                            </th>

                            <th className="px-5 py-3">
                                Attributes
                            </th>

                            <th className="px-5 py-3 text-right">
                                Amount
                            </th>
                        </tr>
                    </thead>

                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {items.map(
                            (item) => (
                                <tr
                                    key={
                                        item.id
                                    }
                                >
                                    <td className="px-5 py-4">
                                        <div className="font-medium">
                                            {
                                                item.component_name
                                            }
                                        </div>

                                        <div className="mt-1 font-mono text-xs text-slate-500">
                                            {
                                                item.component_code
                                            }
                                        </div>
                                    </td>

                                    <td className="px-5 py-4">
                                        {item.calculation_type ===
                                        "fixed" ? (
                                            <div>
                                                Fixed

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {money(
                                                        item.amount,
                                                        currency
                                                    )}
                                                </div>
                                            </div>
                                        ) : (
                                            <div>
                                                {
                                                    item.percentage
                                                }
                                                %

                                                <div className="mt-1 text-xs text-slate-500">
                                                    of{" "}
                                                    {item
                                                        .percentage_of_component
                                                        ?.code ??
                                                        "—"}
                                                </div>
                                            </div>
                                        )}
                                    </td>

                                    <td className="px-5 py-4">
                                        <div className="flex flex-wrap gap-1.5">
                                            {item.is_recurring && (
                                                <Tag>
                                                    Recurring
                                                </Tag>
                                            )}

                                            {item.is_taxable && (
                                                <Tag>
                                                    Taxable
                                                </Tag>
                                            )}

                                            {item.affects_gross_salary && (
                                                <Tag>
                                                    Gross
                                                </Tag>
                                            )}
                                        </div>
                                    </td>

                                    <td className="px-5 py-4 text-right font-semibold">
                                        {money(
                                            item.calculated_amount,
                                            currency
                                        )}
                                    </td>
                                </tr>
                            )
                        )}

                        {items.length ===
                            0 && (
                            <tr>
                                <td
                                    colSpan={
                                        4
                                    }
                                    className="px-5 py-10 text-center text-slate-500"
                                >
                                    No
                                    components.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function Tag({
    children,
}: {
    children: ReactNode;
}) {
    return (
        <span className="inline-flex rounded-full bg-slate-100 px-2 py-1 text-[11px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
            {children}
        </span>
    );
}

function Metric({
    label,
    value,
    emphasis = false,
}: {
    label: string;
    value: string;
    emphasis?: boolean;
}) {
    return (
        <div
            className={`rounded-xl border p-5 ${
                emphasis
                    ? "border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-950"
                    : "border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950"
            }`}
        >
            <div className="text-xs uppercase tracking-wide opacity-60">
                {label}
            </div>

            <div className="mt-2 text-xl font-semibold">
                {value}
            </div>
        </div>
    );
}

function InfoCard({
    icon: Icon,
    title,
    children,
}: {
    icon: ComponentType<{
        className?: string;
    }>;

    title: string;

    children: ReactNode;
}) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
            <div className="flex items-center gap-2 text-sm font-medium text-slate-500">
                <Icon className="h-4 w-4" />

                {title}
            </div>

            <div className="mt-4">
                {children}
            </div>
        </div>
    );
}