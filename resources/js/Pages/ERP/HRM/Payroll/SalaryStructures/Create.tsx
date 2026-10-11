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

type Props = {
    companies: Company[];
    employees: Employee[];
    components: SalaryComponent[];
};

export default function Create({
    companies,
    employees,
    components,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } =
        useForm<SalaryStructureFormData>({
            company_id: "",
            employee_id: "",

            effective_from:
                "",
            effective_to: "",

            status: "active",

            currency: "BDT",

            notes: "",

            items: [],
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        post(
            "/erp/payroll/salary-structures"
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Salary Structure" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href="/erp/payroll/salary-structures"
                        className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Create Salary
                            Structure
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Assign an
                            effective-dated
                            salary structure
                            to an employee.
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
                    submitLabel="Create Salary Structure"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}