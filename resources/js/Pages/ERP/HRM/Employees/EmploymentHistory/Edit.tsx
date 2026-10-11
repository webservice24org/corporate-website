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

import type {
    FormEvent,
} from "react";

type Employee = {
    id: number;
    company_id: number;
    first_name: string;
    last_name: string;
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
    branch_id: number | null;
    name: string;
    code: string;
};

type Designation = {
    id: number;
    company_id: number;
    department_id: number | null;
    name: string;
    code: string;
};

type History = {
    id: number;
    record_type: string;

    company_id: number | null;
    branch_id: number | null;
    department_id: number | null;
    designation_id: number | null;

    employer_name: string | null;
    branch_name: string | null;
    department_name: string | null;
    designation_name: string | null;

    employment_type: string | null;

    start_date: string;
    end_date: string | null;

    is_current: boolean;

    reason_for_leaving: string | null;
    notes: string | null;
};

type Props = {
    employee: Employee;
    history: History;
    companies: Company[];
    branches: Branch[];
    departments: Department[];
    designations: Designation[];
};

function formatDateForInput(
    value: string | null | undefined,
): string {
    if (!value) {
        return "";
    }

    return value.substring(0, 10);
}

export default function Edit({
    employee,
    history,
    companies,
    branches,
    departments,
    designations,
}: Props) {
    const {
        data,
        setData,
        put,
        errors,
        processing,
    } = useForm({
        record_type:
            history.record_type,

        company_id:
            history.company_id
                ? String(
                      history.company_id,
                  )
                : "",

        branch_id:
            history.branch_id
                ? String(
                      history.branch_id,
                  )
                : "",

        department_id:
            history.department_id
                ? String(
                      history.department_id,
                  )
                : "",

        designation_id:
            history.designation_id
                ? String(
                      history.designation_id,
                  )
                : "",

        employer_name:
            history.employer_name ??
            "",

        branch_name:
            history.branch_name ??
            "",

        department_name:
            history.department_name ??
            "",

        designation_name:
            history.designation_name ??
            "",

        employment_type:
            history.employment_type ??
            "",

        start_date:
            formatDateForInput(
                history.start_date,
            ),

        end_date:
            formatDateForInput(
                history.end_date,
            ),

        is_current:
            history.is_current,

        reason_for_leaving:
            history.reason_for_leaving ??
            "",

        notes:
            history.notes ??
            "",
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        put(
            `/erp/employees/${employee.id}/employment-history/${history.id}`,
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Employment History" />

            <div className="mx-auto max-w-5xl">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <BriefcaseBusiness className="size-4" />
                            HRM
                        </div>

                        <h1 className="mt-2 text-2xl font-bold">
                            Edit Employment History
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
                    submitLabel="Update Employment History"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}