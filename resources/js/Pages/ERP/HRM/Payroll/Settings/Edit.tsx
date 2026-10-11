import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";
import type {
    FormEvent,
} from "react";
import {
    ArrowLeft,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

import PayrollSettingForm from "./PayrollSettingForm";

import type {
    PayrollSettingFormData,
} from "./PayrollSettingForm";

type Company = {
    id: number;
    name: string;
};

type PayrollSetting = {
    id: number | null;

    company_id: number;

    pay_frequency:
        PayrollSettingFormData["pay_frequency"];

    salary_proration_basis:
        PayrollSettingFormData["salary_proration_basis"];

    default_currency: string;

    default_pay_day:
        number | null;

    attendance_integration_enabled:
        boolean;

    require_finalized_attendance:
        boolean;

    attendance_deduction_enabled:
        boolean;

    unpaid_leave_deduction_enabled:
        boolean;

    overtime_enabled:
        boolean;

    overtime_rate_multiplier:
        string | number;

    auto_include_recurring_components:
        boolean;

    rounding_method:
        PayrollSettingFormData["rounding_method"];

    rounding_precision:
        number;

    status: boolean;

    notes:
        string | null;
};

type Props = {
    company:
        Company;

    setting:
        PayrollSetting;
};

export default function Edit({
    company,
    setting,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } =
        useForm<PayrollSettingFormData>({
            pay_frequency:
                setting.pay_frequency,

            salary_proration_basis:
                setting.salary_proration_basis,

            default_currency:
                setting.default_currency,

            default_pay_day:
                setting.default_pay_day
                    ? String(
                          setting.default_pay_day
                      )
                    : "",

            attendance_integration_enabled:
                setting.attendance_integration_enabled,

            require_finalized_attendance:
                setting.require_finalized_attendance,

            attendance_deduction_enabled:
                setting.attendance_deduction_enabled,

            unpaid_leave_deduction_enabled:
                setting.unpaid_leave_deduction_enabled,

            overtime_enabled:
                setting.overtime_enabled,

            overtime_rate_multiplier:
                String(
                    setting.overtime_rate_multiplier ??
                        0
                ),

            auto_include_recurring_components:
                setting.auto_include_recurring_components,

            rounding_method:
                setting.rounding_method,

            rounding_precision:
                String(
                    setting.rounding_precision
                ) as
                    | "0"
                    | "2",

            status:
                setting.status,

            notes:
                setting.notes ??
                "",
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        put(
            `/erp/payroll/settings/${company.id}`
        );
    };

    return (
        <ERPLayout>
            <Head
                title={`Payroll Settings - ${company.name}`}
            />

            <div className="mx-auto max-w-6xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href="/erp/payroll/settings"
                        className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Payroll Settings
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            {company.name}
                        </p>
                    </div>
                </div>

                <PayrollSettingForm
                    data={data}
                    setData={
                        setData
                    }
                    errors={
                        errors
                    }
                    processing={
                        processing
                    }
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}