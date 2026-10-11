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

type Props = {
    companies: Company[];
    employees: Employee[];
    shifts: Shift[];

    selectedEmployeeId:
        | number
        | null;
};

export default function Create({
    companies,
    employees,
    shifts,
    selectedEmployeeId,
}: Props) {
    const selectedEmployee =
        selectedEmployeeId
            ? employees.find(
                  (
                      employee,
                  ) =>
                      employee.id ===
                      selectedEmployeeId,
              )
            : null;

    const defaultCompanyId =
        selectedEmployee
            ? String(
                  selectedEmployee.company_id,
              )
            : companies.length
              ? String(
                    companies[0].id,
                )
              : "";

    const {
        data,
        setData,
        post,
        errors,
        processing,
    } = useForm({
        company_id:
            defaultCompanyId,

        employee_id:
            selectedEmployee
                ? String(
                      selectedEmployee.id,
                  )
                : "",

        shift_id: "",

        effective_from:
            "",

        effective_to:
            "",

        status:
            "active",

        notes:
            "",
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        post(
            "/erp/attendance/employee-shifts",
        );
    };

    return (
        <ERPLayout>
            <Head title="Assign Employee Shift" />

            <div className="mx-auto max-w-6xl">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <CalendarClock className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold text-slate-900">
                            Assign Employee Shift
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Assign a shift with an effective date range.
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
                    submitLabel="Assign Shift"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}