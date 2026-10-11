import EmploymentHistoryForm from "@/Components/ERP/HRM/EmploymentHistoryForm";
import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    BriefcaseBusiness,
} from "lucide-react";

import {
    FormEvent,
} from "react";

type Employee = {
    id: number;
    first_name: string;
    last_name: string;
    company_id: number;
};

type Company = {
    id: number;
    name: string;
    code: string;
};

type Branch = {
    id: number;
    company_id: number;
    name: string;
    code: string;
};

type Department = {
    id: number;
    company_id: number;
    branch_id:
        | number
        | null;
    name: string;
    code: string;
};

type Designation = {
    id: number;
    company_id: number;
    department_id:
        | number
        | null;
    name: string;
    code: string;
};

type Props = {
    employee: Employee;
    companies: Company[];
    branches: Branch[];
    departments: Department[];
    designations: Designation[];
};

export default function Create({
    employee,
    companies,
    branches,
    departments,
    designations,
}: Props) {
    const {
        data,
        setData,
        post,
        errors,
        processing,
    } = useForm({
        record_type:
            "internal",

        company_id:
            String(
                employee.company_id,
            ),

        branch_id: "",
        department_id: "",
        designation_id: "",

        employer_name: "",
        branch_name: "",
        department_name: "",
        designation_name: "",

        employment_type: "",

        start_date: "",
        end_date: "",

        is_current: false,

        reason_for_leaving:
            "",

        notes: "",
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        post(
            `/erp/employees/${employee.id}/employment-history`,
        );
    };

    return (
        <ERPLayout>
            <Head title="Add Employment History" />

            <div className="mx-auto max-w-5xl">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <BriefcaseBusiness className="size-4" />
                            HRM
                        </div>

                        <h1 className="mt-2 text-2xl font-bold">
                            Add Employment History
                        </h1>
                    </div>

                    <Link
                        href={`/erp/employees/${employee.id}`}
                        className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm"
                    >
                        <ArrowLeft className="size-4" />

                        Profile
                    </Link>
                </div>

                <EmploymentHistoryForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    companies={
                        companies
                    }
                    branches={
                        branches
                    }
                    departments={
                        departments
                    }
                    designations={
                        designations
                    }
                    processing={
                        processing
                    }
                    submitLabel="Add Employment History"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}