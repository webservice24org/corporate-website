import EmployeeForm from "@/Components/ERP/HRM/EmployeeForm";
import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    UsersRound,
} from "lucide-react";

import {
    FormEvent,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
    country?: string | null;
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

type User = {
    id: number;
    name: string;
    email: string;
};

type Props = {
    companies: Company[];
    branches: Branch[];
    departments: Department[];
    designations: Designation[];
    users: User[];
};

export default function Create({
    companies,
    branches,
    departments,
    designations,
    users,
}: Props) {
    const defaultCompany =
        companies[0] ?? null;

    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm({
        user_id: "",

        company_id:
            defaultCompany
                ? String(
                      defaultCompany.id,
                  )
                : "",

        branch_id: "",
        department_id: "",
        designation_id: "",

        employee_number: "",

        first_name: "",
        middle_name: "",
        last_name: "",
        preferred_name: "",

        work_email: "",
        work_phone: "",
        personal_email: "",
        personal_phone: "",

        date_of_birth: "",
        gender: "",
        marital_status: "",
        nationality: "",
        national_id: "",
        passport_number: "",

        joining_date: "",
        probation_end_date: "",
        confirmation_date: "",
        termination_date: "",

        employment_type:
            "full_time",

        employment_status:
            "active",

        present_address: "",
        permanent_address: "",

        city: "",
        state: "",
        postal_code: "",

        country:
            defaultCompany
                ?.country ??
            "Bangladesh",

        emergency_contact_name:
            "",

        emergency_contact_relationship:
            "",

        emergency_contact_phone:
            "",

        photo:
            null as File | null,

        notes: "",
    });

    const submit = (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        post(
            "/erp/employees",
            {
                forceFormData:
                    true,
            },
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Employee" />

            <div className="mx-auto w-full max-w-6xl">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm text-slate-500">
                            <UsersRound className="size-4" />

                            HRM
                        </div>

                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                            Create Employee
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Create an
                            employee master
                            record.
                        </p>
                    </div>

                    <Link
                        href="/erp/employees"
                        className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        <ArrowLeft className="size-4" />

                        Employees
                    </Link>
                </div>

                <EmployeeForm
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
                    users={users}
                    processing={
                        processing
                    }
                    submitLabel="Create Employee"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}