import {
    Head,
    Link,
} from "@inertiajs/react";
import {
    CalendarDays,
    Clock3,
    Settings2,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";
import {
    useAuthorization,
} from "@/hooks/useAuthorization";

type PayrollSetting = {
    id: number;

    pay_frequency:
        | "monthly"
        | "biweekly"
        | "weekly";

    salary_proration_basis:
        | "calendar_days"
        | "working_days"
        | "fixed_30_days";

    default_currency:
        string;

    default_pay_day:
        number | null;

    attendance_integration_enabled:
        boolean;

    require_finalized_attendance:
        boolean;

    overtime_enabled:
        boolean;

    status:
        boolean;
};

type Company = {
    id: number;
    name: string;
    status:
        string | boolean;

    payroll_setting?:
        PayrollSetting | null;
};

type Props = {
    companies:
        Company[];
};

function labelFrequency(
    value:
        PayrollSetting["pay_frequency"]
): string {
    switch (value) {
        case "biweekly":
            return "Biweekly";

        case "weekly":
            return "Weekly";

        default:
            return "Monthly";
    }
}

function labelProration(
    value:
        PayrollSetting[
        "salary_proration_basis"
        ]
): string {
    switch (value) {
        case "working_days":
            return "Working Days";

        case "fixed_30_days":
            return "Fixed 30 Days";

        default:
            return "Calendar Days";
    }
}

export default function Index({
    companies,
}: Props) {
    const { can } =
        useAuthorization();

    return (
        <ERPLayout>
            <Head title="Payroll Settings" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Payroll Settings
                    </h1>

                    <p className="mt-1 text-sm text-slate-500">
                        Configure payroll
                        policies separately
                        for each company.
                    </p>
                </div>

                <div className="grid gap-4 xl:grid-cols-2">
                    {companies.map(
                        (
                            company
                        ) => {
                            const setting =
                                company.payroll_setting;

                            return (
                                <div
                                    key={
                                        company.id
                                    }
                                    className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950"
                                >
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <h2 className="font-semibold">
                                                {
                                                    company.name
                                                }
                                            </h2>

                                            <div className="mt-2">
                                                {setting ? (
                                                    <span
                                                        className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                            setting.status
                                                                ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                                                : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
                                                        }`}
                                                    >
                                                        {setting.status
                                                            ? "Payroll Active"
                                                            : "Payroll Inactive"}
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                                                        Not Configured
                                                    </span>
                                                )}
                                            </div>
                                        </div>

                                        {can(
                                            "erp.payroll-settings.edit"
                                        ) && (
                                            <Link
                                                href={`/erp/payroll/settings/${company.id}/edit`}
                                                className="inline-flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-sm font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                            >
                                                <Settings2 className="h-4 w-4" />

                                                Configure
                                            </Link>
                                        )}
                                    </div>

                                    {setting ? (
                                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                                            <Info
                                                icon={
                                                    CalendarDays
                                                }
                                                label="Frequency"
                                                value={labelFrequency(
                                                    setting.pay_frequency
                                                )}
                                            />

                                            <Info
                                                icon={
                                                    Clock3
                                                }
                                                label="Proration"
                                                value={labelProration(
                                                    setting.salary_proration_basis
                                                )}
                                            />

                                            <Info
                                                label="Currency"
                                                value={
                                                    setting.default_currency
                                                }
                                            />

                                            <Info
                                                label="Pay Day"
                                                value={
                                                    setting.pay_frequency ===
                                                    "monthly"
                                                        ? setting.default_pay_day
                                                            ? `Day ${setting.default_pay_day}`
                                                            : "Not set"
                                                        : "By payroll period"
                                                }
                                            />

                                            <Info
                                                label="Attendance"
                                                value={
                                                    setting.attendance_integration_enabled
                                                        ? "Integrated"
                                                        : "Disabled"
                                                }
                                            />

                                            <Info
                                                label="Overtime"
                                                value={
                                                    setting.overtime_enabled
                                                        ? "Enabled"
                                                        : "Disabled"
                                                }
                                            />
                                        </div>
                                    ) : (
                                        <div className="mt-5 rounded-lg bg-slate-50 px-4 py-6 text-sm leading-6 text-slate-500 dark:bg-slate-900/50">
                                            No saved
                                            payroll policy
                                            exists for this
                                            company yet.
                                            Open Configure
                                            to create it.
                                        </div>
                                    )}
                                </div>
                            );
                        }
                    )}

                    {companies.length ===
                        0 && (
                        <div className="col-span-full rounded-xl border border-slate-200 bg-white px-6 py-14 text-center text-slate-500 dark:border-slate-800 dark:bg-slate-950">
                            No companies
                            found.
                        </div>
                    )}
                </div>
            </div>
        </ERPLayout>
    );
}

function Info({
    icon: Icon,
    label,
    value,
}: {
    icon?: typeof CalendarDays;
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-900/50">
            <div className="flex items-center gap-2 text-xs text-slate-500">
                {Icon && (
                    <Icon className="h-3.5 w-3.5" />
                )}

                {label}
            </div>

            <div className="mt-1 text-sm font-medium">
                {value}
            </div>
        </div>
    );
}