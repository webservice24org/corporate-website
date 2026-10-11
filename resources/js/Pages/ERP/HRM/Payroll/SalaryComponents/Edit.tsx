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

type SalaryComponent = {
    id: number;

    company_id: number;

    name: string;
    code: string;

    component_type:
        | "earning"
        | "deduction";

    calculation_type:
        | "fixed"
        | "percentage";

    percentage_of_component_id:
        number | null;

    default_amount:
        string | number;

    default_percentage:
        string | number;

    is_taxable: boolean;

    affects_gross_salary:
        boolean;

    is_recurring: boolean;
    status: boolean;

    sort_order: number;

    description:
        | string
        | null;
};

type Props = {
    salaryComponent:
        SalaryComponent;

    companies: Company[];

    baseComponents:
        BaseComponent[];
};

export default function Edit({
    salaryComponent,
    companies,
    baseComponents,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } =
        useForm<SalaryComponentFormData>({
            company_id: String(
                salaryComponent.company_id
            ),

            name:
                salaryComponent.name,

            code:
                salaryComponent.code,

            component_type:
                salaryComponent.component_type,

            calculation_type:
                salaryComponent.calculation_type,

            percentage_of_component_id:
                salaryComponent.percentage_of_component_id
                    ? String(
                          salaryComponent.percentage_of_component_id
                      )
                    : "",

            default_amount: String(
                salaryComponent.default_amount ??
                    0
            ),

            default_percentage:
                String(
                    salaryComponent.default_percentage ??
                        0
                ),

            is_taxable:
                salaryComponent.is_taxable,

            affects_gross_salary:
                salaryComponent.affects_gross_salary,

            is_recurring:
                salaryComponent.is_recurring,

            status:
                salaryComponent.status,

            sort_order: String(
                salaryComponent.sort_order ??
                    0
            ),

            description:
                salaryComponent.description ??
                "",
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        put(
            `/erp/payroll/salary-components/${salaryComponent.id}`
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Salary Component" />

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
                            Edit Salary Component
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Update{" "}
                            <span className="font-medium text-slate-700 dark:text-slate-300">
                                {
                                    salaryComponent.name
                                }
                            </span>
                            .
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
                    submitLabel="Update Component"
                    onSubmit={submit}
                />
            </div>
        </ERPLayout>
    );
}