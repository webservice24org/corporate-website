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

import SalaryStructureForm from "./SalaryStructureForm";

import type {
    Company,
    Employee,
    SalaryComponent,
    SalaryStructureFormData,
} from "./SalaryStructureForm";

type StructureItem = {
    salary_component_id:
        number;

    amount:
        string | number;

    percentage:
        string | number;
};

type SalaryStructure = {
    id: number;

    company_id:
        number;

    employee_id:
        number;

    effective_from:
        string;

    effective_to:
        string | null;

    status:
        | "active"
        | "inactive";

    currency:
        string;

    notes:
        string | null;

    items:
        StructureItem[];
};

type Props = {
    salaryStructure:
        SalaryStructure;

    companies: Company[];
    employees: Employee[];
    components: SalaryComponent[];
};

export default function Edit({
    salaryStructure,
    companies,
    employees,
    components,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } =
        useForm<SalaryStructureFormData>({
            company_id: String(
                salaryStructure.company_id
            ),

            employee_id: String(
                salaryStructure.employee_id
            ),

            effective_from:
                salaryStructure.effective_from,

            effective_to:
                salaryStructure.effective_to ??
                "",

            status:
                salaryStructure.status,

            currency:
                salaryStructure.currency,

            notes:
                salaryStructure.notes ??
                "",

            items:
                salaryStructure.items.map(
                    (item) => ({
                        salary_component_id:
                            String(
                                item.salary_component_id
                            ),

                        amount:
                            String(
                                item.amount ??
                                    0
                            ),

                        percentage:
                            String(
                                item.percentage ??
                                    0
                            ),
                    })
                ),
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        put(
            `/erp/payroll/salary-structures/${salaryStructure.id}`
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Salary Structure" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href={`/erp/payroll/salary-structures/${salaryStructure.id}`}
                        className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Edit Salary
                            Structure
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Update the
                            employee's
                            effective salary
                            structure.
                        </p>
                    </div>
                </div>

                <SalaryStructureForm
                    data={data}
                    setData={setData}
                    errors={
                        errors
                    }
                    processing={
                        processing
                    }
                    companies={
                        companies
                    }
                    employees={
                        employees
                    }
                    components={
                        components
                    }
                    submitLabel="Update Salary Structure"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}