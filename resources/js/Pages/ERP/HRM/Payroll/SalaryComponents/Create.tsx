import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";
import type { FormEvent } from "react";
import { ArrowLeft } from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";
import SalaryComponentForm from "./SalaryComponentForm";
import type {
    SalaryComponentFormData,
} from "./SalaryComponentForm";

type Company = {
    id: number;
    name: string;
};

type BaseComponent = {
    id: number;
    company_id: number;
    name: string;
    code: string;
    component_type:
        | "earning"
        | "deduction";
};

type Props = {
    companies: Company[];
    baseComponents: BaseComponent[];
};

export default function Create({
    companies,
    baseComponents,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } =
        useForm<SalaryComponentFormData>({
            company_id: "",

            name: "",
            code: "",

            component_type:
                "earning",

            calculation_type:
                "fixed",

            percentage_of_component_id:
                "",

            default_amount: "0",
            default_percentage: "0",

            is_taxable: false,

            affects_gross_salary:
                true,

            is_recurring: true,
            status: true,

            sort_order: "0",
            description: "",
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        post(
            "/erp/payroll/salary-components"
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Salary Component" />

            <div className="mx-auto max-w-5xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href="/erp/payroll/salary-components"
                        className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Create Salary Component
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Define a reusable
                            earning or deduction
                            component for payroll.
                        </p>
                    </div>
                </div>

                <SalaryComponentForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={
                        processing
                    }
                    companies={
                        companies
                    }
                    baseComponents={
                        baseComponents
                    }
                    submitLabel="Create Component"
                    onSubmit={submit}
                />
            </div>
        </ERPLayout>
    );
}