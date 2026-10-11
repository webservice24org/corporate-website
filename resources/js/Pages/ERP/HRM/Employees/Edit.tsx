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

type Employee = {
    id: number;

    user_id:
        | number
        | null;

    company_id: number;

    branch_id:
        | number
        | null;

    department_id:
        | number
        | null;

    designation_id:
        | number
        | null;

    employee_number: string;

    first_name: string;

    middle_name:
        | string
        | null;

    last_name: string;

    preferred_name:
        | string
        | null;

    work_email:
        | string
        | null;

    work_phone:
        | string
        | null;

    personal_email:
        | string
        | null;

    personal_phone:
        | string
        | null;

    date_of_birth:
        | string
        | null;

    gender:
        | string
        | null;

    marital_status:
        | string
        | null;

    nationality:
        | string
        | null;

    national_id:
        | string
        | null;

    passport_number:
        | string
        | null;

    joining_date:
        | string
        | null;

    probation_end_date:
        | string
        | null;

    confirmation_date:
        | string
        | null;

    termination_date:
        | string
        | null;

    employment_type: string;

    employment_status: string;

    present_address:
        | string
        | null;

    permanent_address:
        | string
        | null;

    city:
        | string
        | null;

    state:
        | string
        | null;

    postal_code:
        | string
        | null;

    country:
        | string
        | null;

    emergency_contact_name:
        | string
        | null;

    emergency_contact_relationship:
        | string
        | null;

    emergency_contact_phone:
        | string
        | null;

    photo:
        | string
        | null;

    notes:
        | string
        | null;
};

type Props = {
    employee: Employee;

    companies: Company[];

    branches: Branch[];

    departments: Department[];

    designations: Designation[];

    users: User[];
};

function formatDateForInput(
    value:
        | string
        | null
        | undefined,
): string {
    if (!value) {
        return "";
    }

    return value.substring(
        0,
        10,
    );
}

export default function Edit({
    employee,
    companies,
    branches,
    departments,
    designations,
    users,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm({
        user_id:
            employee.user_id
                ? String(
                      employee.user_id,
                  )
                : "",

        company_id:
            String(
                employee.company_id,
            ),

        branch_id:
            employee.branch_id
                ? String(
                      employee.branch_id,
                  )
                : "",

        department_id:
            employee.department_id
                ? String(
                      employee.department_id,
                  )
                : "",

        designation_id:
            employee.designation_id
                ? String(
                      employee.designation_id,
                  )
                : "",

        employee_number:
            employee.employee_number,

        first_name:
            employee.first_name,

        middle_name:
            employee.middle_name ??
            "",

        last_name:
            employee.last_name,

        preferred_name:
            employee.preferred_name ??
            "",

        work_email:
            employee.work_email ??
            "",

        work_phone:
            employee.work_phone ??
            "",

        personal_email:
            employee.personal_email ??
            "",

        personal_phone:
            employee.personal_phone ??
            "",

        date_of_birth:
        formatDateForInput(
            employee.date_of_birth,
        ),

        gender:
            employee.gender ??
            "",

        marital_status:
            employee.marital_status ??
            "",

        nationality:
            employee.nationality ??
            "",

        national_id:
            employee.national_id ??
            "",

        passport_number:
            employee.passport_number ??
            "",

        joining_date:
        formatDateForInput(
            employee.joining_date,
        ),

        probation_end_date:
        formatDateForInput(
            employee.probation_end_date,
        ),

        confirmation_date:
        formatDateForInput(
            employee.confirmation_date,
        ),

        termination_date:
        formatDateForInput(
            employee.termination_date,
        ),

        employment_type:
            employee.employment_type,

        employment_status:
            employee.employment_status,

        present_address:
            employee.present_address ??
            "",

        permanent_address:
            employee.permanent_address ??
            "",

        city:
            employee.city ??
            "",

        state:
            employee.state ??
            "",

        postal_code:
            employee.postal_code ??
            "",

        country:
            employee.country ??
            "",

        emergency_contact_name:
            employee.emergency_contact_name ??
            "",

        emergency_contact_relationship:
            employee.emergency_contact_relationship ??
            "",

        emergency_contact_phone:
            employee.emergency_contact_phone ??
            "",

        photo:
            null as File | null,

        notes:
            employee.notes ??
            "",
    });

    const submit = (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        post(
            `/erp/employees/${employee.id}`,
            {
                forceFormData:
                    true,
            },
        );
    };

    return (
        <ERPLayout>
            <Head
                title={`Edit ${employee.first_name} ${employee.last_name}`}
            />

            <div className="mx-auto w-full max-w-6xl">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm text-slate-500">
                            <UsersRound className="size-4" />

                            HRM
                        </div>

                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                            Edit Employee
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Update{" "}
                            {
                                employee.first_name
                            }{" "}
                            {
                                employee.last_name
                            }
                            .
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
                    users={
                        users
                    }
                    processing={
                        processing
                    }
                    submitLabel="Update Employee"
                    currentPhoto={
                        employee.photo
                    }
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}