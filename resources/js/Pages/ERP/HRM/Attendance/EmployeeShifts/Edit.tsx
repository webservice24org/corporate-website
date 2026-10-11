import EmployeeShiftAssignmentForm from "@/Components/ERP/HRM/EmployeeShiftAssignmentForm";
import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    CalendarClock,
} from "lucide-react";

import type {
    FormEvent,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

type Employee = {
    id: number;
    company_id: number;

    employee_number: string;

    first_name: string;

    middle_name:
        | string
        | null;

    last_name: string;

    employment_status:
        string;

    company:
        | Company
        | null;

    branch:
        | {
              id: number;
              name: string;
              code: string;
          }
        | null;

    department:
        | {
              id: number;
              name: string;
              code: string;
          }
        | null;

    designation:
        | {
              id: number;
              name: string;
              code: string;
          }
        | null;
};

type Shift = {
    id: number;

    company_id: number;

    name: string;
    code: string;

    start_time: string;
    end_time: string;

    crosses_midnight:
        boolean;

    status: string;
};

type Assignment = {
    id: number;

    employee_id: number;
    shift_id: number;

    effective_from:
        string;

    effective_to:
        | string
        | null;

    status: string;

    notes:
        | string
        | null;

    employee: Employee;

    shift: Shift;
};

type Props = {
    assignment:
        Assignment;

    companies:
        Company[];

    employees:
        Employee[];

    shifts:
        Shift[];
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
    assignment,
    companies,
    employees,
    shifts,
}: Props) {
    const {
        data,
        setData,
        put,
        errors,
        processing,
    } = useForm({
        company_id:
            String(
                assignment
                    .employee
                    .company_id,
            ),

        employee_id:
            String(
                assignment.employee_id,
            ),

        shift_id:
            String(
                assignment.shift_id,
            ),

        effective_from:
            formatDateForInput(
                assignment.effective_from,
            ),

        effective_to:
            formatDateForInput(
                assignment.effective_to,
            ),

        status:
            assignment.status,

        notes:
            assignment.notes ??
            "",
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        put(
            `/erp/attendance/employee-shifts/${assignment.id}`,
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Employee Shift Assignment" />

            <div className="mx-auto max-w-6xl">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <CalendarClock className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold text-slate-900">
                            Edit Shift Assignment
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Update the employee's assigned shift and effective period.
                        </p>
                    </div>

                    <Link
                        href="/erp/attendance/employee-shifts"
                        className="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 text-sm"
                    >
                        <ArrowLeft className="size-4" />

                        Shift Assignments
                    </Link>
                </div>

                <EmployeeShiftAssignmentForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    companies={
                        companies
                    }
                    employees={
                        employees
                    }
                    shifts={
                        shifts
                    }
                    processing={
                        processing
                    }
                    submitLabel="Update Assignment"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}