import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";
import {
    ArrowLeft,
    Paperclip,
} from "lucide-react";
import type { FormEvent } from "react";

import ERPLayout from "@/Layouts/erp-layout";

type Employee = {
    id: number;
    company_id: number;
    employee_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
};

type LeaveType = {
    id: number;
    company_id: number;
    name: string;
    code: string;
    annual_allowance: string | number;
    is_paid: boolean;
    allow_half_day: boolean;
    requires_attachment: boolean;
};

type Props = {
    employees: Employee[];
    leaveTypes: LeaveType[];
};

type LeaveRequestForm = {
    employee_id: string;
    leave_type_id: string;
    start_date: string;
    end_date: string;
    duration_type: "full_day" | "half_day";
    half_day_period:
        | ""
        | "first_half"
        | "second_half";
    reason: string;
    attachment: File | null;
};

function employeeName(
    employee: Employee
): string {
    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

export default function Create({
    employees,
    leaveTypes,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm<LeaveRequestForm>({
        employee_id: "",
        leave_type_id: "",
        start_date: "",
        end_date: "",
        duration_type: "full_day",
        half_day_period: "",
        reason: "",
        attachment: null,
    });

    const selectedEmployee =
        employees.find(
            (employee) =>
                String(employee.id) ===
                data.employee_id
        );

    const availableLeaveTypes =
        selectedEmployee
            ? leaveTypes.filter(
                  (type) =>
                      type.company_id ===
                      selectedEmployee.company_id
              )
            : [];

    const selectedLeaveType =
        leaveTypes.find(
            (type) =>
                String(type.id) ===
                data.leave_type_id
        );

    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-slate-400 dark:border-slate-800 dark:bg-slate-950";

    const submit = (
        event: FormEvent
    ) => {
        event.preventDefault();

        post("/erp/leave/requests", {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <ERPLayout>
            <Head title="New Leave Request" />

            <div className="mx-auto max-w-4xl space-y-6">
                <div className="flex items-center gap-4">
                    <Link
                        href="/erp/leave/requests"
                        className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold">
                            New Leave Request
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Submit leave on behalf of
                            an employee.
                        </p>
                    </div>
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-6"
                >
                    <div className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                        <div className="grid gap-5 md:grid-cols-2">
                            <div className="md:col-span-2">
                                <label className="mb-1.5 block text-sm font-medium">
                                    Employee *
                                </label>

                                <select
                                    value={
                                        data.employee_id
                                    }
                                    onChange={(
                                        event
                                    ) => {
                                        setData(
                                            "employee_id",
                                            event.target
                                                .value
                                        );

                                        setData(
                                            "leave_type_id",
                                            ""
                                        );
                                    }}
                                    className={
                                        inputClass
                                    }
                                >
                                    <option value="">
                                        Select
                                        employee
                                    </option>

                                    {employees.map(
                                        (
                                            employee
                                        ) => (
                                            <option
                                                key={
                                                    employee.id
                                                }
                                                value={
                                                    employee.id
                                                }
                                            >
                                                {
                                                    employee.employee_number
                                                }{" "}
                                                —{" "}
                                                {employeeName(
                                                    employee
                                                )}
                                            </option>
                                        )
                                    )}
                                </select>

                                {errors.employee_id && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {
                                            errors.employee_id
                                        }
                                    </p>
                                )}
                            </div>

                            <div className="md:col-span-2">
                                <label className="mb-1.5 block text-sm font-medium">
                                    Leave Type *
                                </label>

                                <select
                                    value={
                                        data.leave_type_id
                                    }
                                    disabled={
                                        !selectedEmployee
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "leave_type_id",
                                            event.target
                                                .value
                                        )
                                    }
                                    className={
                                        inputClass
                                    }
                                >
                                    <option value="">
                                        Select leave
                                        type
                                    </option>

                                    {availableLeaveTypes.map(
                                        (type) => (
                                            <option
                                                key={
                                                    type.id
                                                }
                                                value={
                                                    type.id
                                                }
                                            >
                                                {
                                                    type.name
                                                }{" "}
                                                (
                                                {
                                                    type.code
                                                }
                                                ) —{" "}
                                                {
                                                    type.annual_allowance
                                                }{" "}
                                                day(s)
                                            </option>
                                        )
                                    )}
                                </select>

                                {errors.leave_type_id && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {
                                            errors.leave_type_id
                                        }
                                    </p>
                                )}

                                {selectedLeaveType && (
                                    <div className="mt-2 flex flex-wrap gap-2 text-xs">
                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 dark:bg-slate-800">
                                            {selectedLeaveType.is_paid
                                                ? "Paid Leave"
                                                : "Unpaid Leave"}
                                        </span>

                                        {selectedLeaveType.requires_attachment && (
                                            <span className="rounded-full bg-amber-100 px-2.5 py-1 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                                Attachment
                                                required
                                            </span>
                                        )}
                                    </div>
                                )}
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Start Date *
                                </label>

                                <input
                                    type="date"
                                    value={
                                        data.start_date
                                    }
                                    onChange={(
                                        event
                                    ) => {
                                        setData(
                                            "start_date",
                                            event.target
                                                .value
                                        );

                                        if (
                                            !data.end_date
                                        ) {
                                            setData(
                                                "end_date",
                                                event
                                                    .target
                                                    .value
                                            );
                                        }
                                    }}
                                    className={
                                        inputClass
                                    }
                                />

                                {errors.start_date && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {
                                            errors.start_date
                                        }
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    End Date *
                                </label>

                                <input
                                    type="date"
                                    value={
                                        data.end_date
                                    }
                                    min={
                                        data.start_date ||
                                        undefined
                                    }
                                    disabled={
                                        data.duration_type ===
                                        "half_day"
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "end_date",
                                            event.target
                                                .value
                                        )
                                    }
                                    className={
                                        inputClass
                                    }
                                />

                                {errors.end_date && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {
                                            errors.end_date
                                        }
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Duration *
                                </label>

                                <select
                                    value={
                                        data.duration_type
                                    }
                                    onChange={(
                                        event
                                    ) => {
                                        const value =
                                            event.target
                                                .value as
                                                | "full_day"
                                                | "half_day";

                                        setData(
                                            "duration_type",
                                            value
                                        );

                                        if (
                                            value ===
                                            "half_day"
                                        ) {
                                            setData(
                                                "end_date",
                                                data.start_date
                                            );
                                        } else {
                                            setData(
                                                "half_day_period",
                                                ""
                                            );
                                        }
                                    }}
                                    className={
                                        inputClass
                                    }
                                >
                                    <option value="full_day">
                                        Full Day
                                    </option>

                                    {selectedLeaveType?.allow_half_day && (
                                        <option value="half_day">
                                            Half Day
                                        </option>
                                    )}
                                </select>
                            </div>

                            {data.duration_type ===
                                "half_day" && (
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium">
                                        Half Day
                                        Period *
                                    </label>

                                    <select
                                        value={
                                            data.half_day_period
                                        }
                                        onChange={(
                                            event
                                        ) =>
                                            setData(
                                                "half_day_period",
                                                event
                                                    .target
                                                    .value as
                                                    | ""
                                                    | "first_half"
                                                    | "second_half"
                                            )
                                        }
                                        className={
                                            inputClass
                                        }
                                    >
                                        <option value="">
                                            Select
                                            period
                                        </option>

                                        <option value="first_half">
                                            First Half
                                        </option>

                                        <option value="second_half">
                                            Second Half
                                        </option>
                                    </select>

                                    {errors.half_day_period && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {
                                                errors.half_day_period
                                            }
                                        </p>
                                    )}
                                </div>
                            )}

                            <div className="md:col-span-2">
                                <label className="mb-1.5 block text-sm font-medium">
                                    Reason
                                </label>

                                <textarea
                                    rows={5}
                                    value={
                                        data.reason
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "reason",
                                            event.target
                                                .value
                                        )
                                    }
                                    className="w-full rounded-md border border-slate-200 bg-white p-3 text-sm outline-none focus:border-slate-400 dark:border-slate-800 dark:bg-slate-950"
                                    placeholder="Reason for leave..."
                                />

                                {errors.reason && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {
                                            errors.reason
                                        }
                                    </p>
                                )}
                            </div>

                            <div className="md:col-span-2">
                                <label className="mb-1.5 block text-sm font-medium">
                                    Attachment
                                    {selectedLeaveType?.requires_attachment &&
                                        " *"}
                                </label>

                                <label className="flex cursor-pointer items-center gap-3 rounded-lg border border-dashed border-slate-300 p-4 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-900">
                                    <Paperclip className="h-5 w-5 text-slate-400" />

                                    <div className="text-sm">
                                        {data.attachment
                                            ? data
                                                  .attachment
                                                  .name
                                            : "Choose PDF or image"}
                                    </div>

                                    <input
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                                        className="hidden"
                                        onChange={(
                                            event
                                        ) =>
                                            setData(
                                                "attachment",
                                                event
                                                    .target
                                                    .files?.[0] ??
                                                    null
                                            )
                                        }
                                    />
                                </label>

                                {errors.attachment && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {
                                            errors.attachment
                                        }
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link
                            href="/erp/leave/requests"
                            className="inline-flex h-10 items-center rounded-md border border-slate-200 px-4 text-sm font-medium dark:border-slate-800"
                        >
                            Cancel
                        </Link>

                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex h-10 items-center rounded-md bg-slate-900 px-5 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
                        >
                            {processing
                                ? "Submitting..."
                                : "Submit Request"}
                        </button>
                    </div>
                </form>
            </div>
        </ERPLayout>
    );
}