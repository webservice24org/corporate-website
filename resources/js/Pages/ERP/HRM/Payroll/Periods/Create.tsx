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

type Props = {
    companies:
        Company[];

    payrollSettings:
        PayrollSetting[];

    selectedCompanyId:
        number | null;
};

export default function Create({
    companies,
    payrollSettings,
    selectedCompanyId,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } =
        useForm<PayrollPeriodFormData>({
            company_id:
                selectedCompanyId
                    ? String(
                          selectedCompanyId
                      )
                    : "",

            name: "",
            code: "",

            start_date: "",
            end_date: "",
            payment_date: "",

            status:
                "draft",

            notes: "",
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        post(
            "/erp/payroll/periods"
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Payroll Period" />

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
                            Create Payroll
                            Period
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Define a payroll
                            cycle before
                            employee payroll
                            is processed.
                        </p>
                    </div>
                </div>

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
                        processing
                    }
                    submitLabel="Create Payroll Period"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}