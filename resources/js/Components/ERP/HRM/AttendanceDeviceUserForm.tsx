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

type Device = {
    id: number;
    company_id: number;

    name: string;
    code: string;

    provider: string;

    model:
        | string
        | null;
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
};

export type AttendanceDeviceUserFormData = {
    company_id: string;

    device_id: string;
    employee_id: string;

    external_user_id: string;
    card_number: string;

    status: string;
    notes: string;
};

type Props = {
    data:
        AttendanceDeviceUserFormData;

    setData: <
        K extends keyof AttendanceDeviceUserFormData,
    >(
        key: K,
        value:
            AttendanceDeviceUserFormData[K],
    ) => void;

    errors: Partial<
        Record<
            keyof AttendanceDeviceUserFormData,
            string
        >
    >;

    companies: Company[];
    devices: Device[];
    employees: Employee[];

    processing: boolean;
    submitLabel: string;

    onSubmit: (
        event:
            FormEvent<HTMLFormElement>,
    ) => void;
};

export default function AttendanceDeviceUserForm({
    data,
    setData,
    errors,
    companies,
    devices,
    employees,
    processing,
    submitLabel,
    onSubmit,
}: Props) {
    const availableDevices =
        useMemo(
            () =>
                devices.filter(
                    (
                        device,
                    ) =>
                        String(
                            device.company_id,
                        ) ===
                        data.company_id,
                ),
            [
                devices,
                data.company_id,
            ],
        );

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

    const changeCompany = (
        companyId: string,
    ) => {
        setData(
            "company_id",
            companyId,
        );

        setData(
            "device_id",
            "",
        );

        setData(
            "employee_id",
            "",
        );
    };

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <Card
                title="Device Employee Mapping"
                description="Map an ERP employee to the user identifier used by the biometric device."
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
                                        }
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
                        label="Attendance Device"
                        required
                        error={
                            errors.device_id
                        }
                    >
                        <select
                            value={
                                data.device_id
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "device_id",
                                    event.target.value,
                                )
                            }
                            disabled={
                                !data.company_id
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select device
                            </option>

                            {availableDevices.map(
                                (
                                    device,
                                ) => (
                                    <option
                                        key={
                                            device.id
                                        }
                                        value={
                                            device.id
                                        }
                                    >
                                        {
                                            device.name
                                        }{" "}
                                        (
                                        {
                                            device.code
                                        }
                                        )
                                    </option>
                                ),
                            )}
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
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "employee_id",
                                    event.target.value,
                                )
                            }
                            disabled={
                                !data.company_id
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
                                        {employeeName(
                                            employee,
                                        )}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Device User ID / PIN"
                        required
                        error={
                            errors.external_user_id
                        }
                    >
                        <input
                            value={
                                data.external_user_id
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "external_user_id",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                            placeholder="Example: 1001"
                        />

                        <p className="mt-1 text-xs text-slate-500">
                            User identifier stored on the attendance device.
                        </p>
                    </Field>

                    <Field
                        label="Card Number"
                        error={
                            errors.card_number
                        }
                    >
                        <input
                            value={
                                data.card_number
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "card_number",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>
                </div>

                <div className="mt-5">
                    <Field
                        label="Notes"
                        error={
                            errors.notes
                        }
                    >
                        <textarea
                            rows={4}
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
                    </Field>
                </div>
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

function employeeName(
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

const inputClass =
    "block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200 disabled:bg-slate-100";