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

import PayrollPeriodForm from "./PayrollPeriodForm";

import type {
    Company,
    PayrollPeriodFormData,
    PayrollSetting,
} from "./PayrollPeriodForm";

type PayrollPeriod = {
    id: number;

    company_id:
        number;

    name:
        string;

    code:
        string;

    start_date:
        string;

    end_date:
        string;

    payment_date:
        string | null;

    status:
        | "draft"
        | "open"
        | "processing"
        | "closed";

    is_locked:
        boolean;

    notes:
        string | null;
};

type Props = {
    payrollPeriod:
        PayrollPeriod;

    companies:
        Company[];

    payrollSettings:
        PayrollSetting[];
};

export default function Edit({
    payrollPeriod,
    companies,
    payrollSettings,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } =
        useForm<PayrollPeriodFormData>({
            company_id:
                String(
                    payrollPeriod.company_id
                ),

            name:
                payrollPeriod.name,

            code:
                payrollPeriod.code,

            start_date:
                payrollPeriod.start_date,

            end_date:
                payrollPeriod.end_date,

            payment_date:
                payrollPeriod.payment_date ??
                "",

            status:
                payrollPeriod.status ===
                "processing"
                    ? "open"
                    : payrollPeriod.status,

            notes:
                payrollPeriod.notes ??
                "",
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        put(
            `/erp/payroll/periods/${payrollPeriod.id}`
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Payroll Period" />

            <div className="mx-auto max-w-6xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href="/erp/payroll/periods"
                        className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Edit Payroll
                            Period
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            {
                                payrollPeriod.name
                            }{" "}
                            ·{" "}
                            {
                                payrollPeriod.code
                            }
                        </p>
                    </div>
                </div>

                {payrollPeriod.is_locked && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                        This payroll period
                        is locked. The
                        backend will reject
                        changes.
                    </div>
                )}

                <PayrollPeriodForm
                    data={data}
                    setData={
                        setData
                    }
                    companies={
                        companies
                    }
                    payrollSettings={
                        payrollSettings
                    }
                    errors={
                        errors
                    }
                    processing={
                        processing ||
                        payrollPeriod.is_locked
                    }
                    submitLabel="Update Payroll Period"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}