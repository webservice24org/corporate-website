import {
    useMemo,
} from "react";

import type {
    FormEvent,
    ReactNode,
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

export type EmployeeShiftAssignmentFormData = {
    company_id: string;

    employee_id: string;

    shift_id: string;

    effective_from:
        string;

    effective_to:
        string;

    status: string;

    notes: string;
};

type Props = {
    data:
        EmployeeShiftAssignmentFormData;

    setData: <
        K extends keyof EmployeeShiftAssignmentFormData,
    >(
        key: K,
        value:
            EmployeeShiftAssignmentFormData[K],
    ) => void;

    errors: Partial<
        Record<
            keyof EmployeeShiftAssignmentFormData,
            string
        >
    >;

    companies: Company[];

    employees: Employee[];

    shifts: Shift[];

    processing: boolean;

    submitLabel: string;

    onSubmit: (
        event:
            FormEvent<HTMLFormElement>,
    ) => void;
};

export default function EmployeeShiftAssignmentForm({
    data,
    setData,
    errors,
    companies,
    employees,
    shifts,
    processing,
    submitLabel,
    onSubmit,
}: Props) {
    const availableEmployees =
        useMemo(
            () =>
                employees.filter(
                    (
                        employee,
                    ) =>
                        String(
                            employee.company_id,
                        ) ===
                        data.company_id,
                ),
            [
                employees,
                data.company_id,
            ],
        );

    const availableShifts =
        useMemo(
            () =>
                shifts.filter(
                    (
                        shift,
                    ) =>
                        String(
                            shift.company_id,
                        ) ===
                        data.company_id,
                ),
            [
                shifts,
                data.company_id,
            ],
        );

    const selectedEmployee =
        useMemo(
            () =>
                employees.find(
                    (
                        employee,
                    ) =>
                        String(
                            employee.id,
                        ) ===
                        data.employee_id,
                ) ?? null,
            [
                employees,
                data.employee_id,
            ],
        );

    const selectedShift =
        useMemo(
            () =>
                shifts.find(
                    (
                        shift,
                    ) =>
                        String(
                            shift.id,
                        ) ===
                        data.shift_id,
                ) ?? null,
            [
                shifts,
                data.shift_id,
            ],
        );

    const changeCompany = (
        companyId: string,
    ) => {
        setData(
            'company_id',
            companyId,
        );

        setData(
            'employee_id',
            '',
        );

        setData(
            'shift_id',
            '',
        );
    };

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <Card
                title="Assignment"
                description="Assign an employee to a shift for a specific effective period."
            >
                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="Company"
                        required
                    >
                        <select
                            value={
                                data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                changeCompany(
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select company
                            </option>

                            {companies.map(
                                (
                                    company,
                                ) => (
                                    <option
                                        key={
                                            company.id
                                        }
                                        value={
                                            company.id
                                        }
                                    >
                                        {
                                            company.name
                                        }{" "}
                                        (
                                        {
                                            company.code
                                        }
                                        )
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Status"
                        required
                        error={
                            errors.status
                        }
                    >
                        <select
                            value={
                                data.status
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "status",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Employee"
                        required
                        error={
                            errors.employee_id
                        }
                    >
                        <select
                            value={
                                data.employee_id
                            }
                            disabled={
                                !data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "employee_id",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select employee
                            </option>

                            {availableEmployees.map(
                                (
                                    employee,
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
                                        }
                                        {" — "}
                                        {employeeFullName(
                                            employee,
                                        )}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Shift"
                        required
                        error={
                            errors.shift_id
                        }
                    >
                        <select
                            value={
                                data.shift_id
                            }
                            disabled={
                                !data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "shift_id",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select shift
                            </option>

                            {availableShifts.map(
                                (
                                    shift,
                                ) => (
                                    <option
                                        key={
                                            shift.id
                                        }
                                        value={
                                            shift.id
                                        }
                                    >
                                        {
                                            shift.name
                                        }{" "}
                                        (
                                        {
                                            shift.code
                                        }
                                        )
                                        {" — "}
                                        {formatTime(
                                            shift.start_time,
                                        )}
                                        {" → "}
                                        {formatTime(
                                            shift.end_time,
                                        )}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Effective From"
                        required
                        error={
                            errors.effective_from
                        }
                    >
                        <input
                            type="date"
                            value={
                                data.effective_from
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "effective_from",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Effective To"
                        error={
                            errors.effective_to
                        }
                    >
                        <input
                            type="date"
                            value={
                                data.effective_to
                            }
                            min={
                                data.effective_from ||
                                undefined
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "effective_to",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />

                        <p className="mt-1 text-xs text-slate-500">
                            Leave empty if this assignment continues until changed.
                        </p>
                    </Field>
                </div>
            </Card>

            {selectedEmployee && (
                <Card
                    title="Employee"
                    description="Current organizational placement of the selected employee."
                >
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <Info
                            label="Employee"
                            value={
                                employeeFullName(
                                    selectedEmployee,
                                )
                            }
                        />

                        <Info
                            label="Number"
                            value={
                                selectedEmployee.employee_number
                            }
                        />

                        <Info
                            label="Branch"
                            value={
                                selectedEmployee.branch?.name
                            }
                        />

                        <Info
                            label="Department"
                            value={
                                selectedEmployee.department?.name
                            }
                        />

                        <Info
                            label="Designation"
                            value={
                                selectedEmployee.designation?.name
                            }
                        />

                        <Info
                            label="Employment Status"
                            value={
                                humanize(
                                    selectedEmployee.employment_status,
                                )
                            }
                        />
                    </div>
                </Card>
            )}

            {selectedShift && (
                <Card
                    title="Selected Shift"
                    description="The schedule that will apply during this assignment period."
                >
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <Info
                            label="Shift"
                            value={
                                selectedShift.name
                            }
                        />

                        <Info
                            label="Code"
                            value={
                                selectedShift.code
                            }
                        />

                        <Info
                            label="Start"
                            value={formatTime(
                                selectedShift.start_time,
                            )}
                        />

                        <Info
                            label="End"
                            value={formatTime(
                                selectedShift.end_time,
                            )}
                        />

                        <Info
                            label="Type"
                            value={
                                selectedShift.crosses_midnight
                                    ? "Overnight"
                                    : "Same Day"
                            }
                        />
                    </div>
                </Card>
            )}

            <Card
                title="Notes"
                description="Optional HR notes about this shift assignment."
            >
                <textarea
                    rows={5}
                    value={
                        data.notes
                    }
                    onChange={(
                        event,
                    ) =>
                        setData(
                            "notes",
                            event.target.value,
                        )
                    }
                    className={
                        inputClass
                    }
                />

                {errors.notes && (
                    <p className="mt-1 text-sm text-red-600">
                        {
                            errors.notes
                        }
                    </p>
                )}
            </Card>

            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={
                        processing
                    }
                    className="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    {processing
                        ? "Saving..."
                        : submitLabel}
                </button>
            </div>
        </form>
    );
}

function Card({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-white shadow-sm">
            <div className="border-b px-6 py-4">
                <h2 className="font-semibold text-slate-900">
                    {title}
                </h2>

                <p className="mt-1 text-sm text-slate-500">
                    {description}
                </p>
            </div>

            <div className="p-6">
                {children}
            </div>
        </section>
    );
}

function Field({
    label,
    children,
    required = false,
    error,
}: {
    label: string;
    children: ReactNode;
    required?: boolean;
    error?: string;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
                {label}

                {required && (
                    <span className="ml-1 text-red-500">
                        *
                    </span>
                )}
            </label>

            {children}

            {error && (
                <p className="mt-1 text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

function Info({
    label,
    value,
}: {
    label: string;

    value:
        | string
        | null
        | undefined;
}) {
    return (
        <div>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-400">
                {label}
            </div>

            <div className="mt-1 text-sm font-medium text-slate-700">
                {value || "—"}
            </div>
        </div>
    );
}

function employeeFullName(
    employee: Employee,
): string {
    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

function formatTime(
    value: string,
): string {
    return value.substring(
        0,
        5,
    );
}

function humanize(
    value: string,
): string {
    return value
        .replaceAll(
            "_",
            " ",
        )
        .replace(
            /\b\w/g,
            (
                character,
            ) =>
                character.toUpperCase(),
        );
}

const inputClass =
    "block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200 disabled:bg-slate-100";