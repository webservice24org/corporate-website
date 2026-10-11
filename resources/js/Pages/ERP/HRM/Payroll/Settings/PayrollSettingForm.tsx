import type {
    FormEvent,
    ReactNode,
} from "react";

export type PayrollSettingFormData = {
    pay_frequency:
        | "monthly"
        | "biweekly"
        | "weekly";

    salary_proration_basis:
        | "calendar_days"
        | "working_days"
        | "fixed_30_days";

    default_currency:
        string;

    default_pay_day:
        string;

    attendance_integration_enabled:
        boolean;

    require_finalized_attendance:
        boolean;

    attendance_deduction_enabled:
        boolean;

    unpaid_leave_deduction_enabled:
        boolean;

    overtime_enabled:
        boolean;

    overtime_rate_multiplier:
        string;

    auto_include_recurring_components:
        boolean;

    rounding_method:
        | "none"
        | "nearest"
        | "up"
        | "down";

    rounding_precision:
        "0" | "2";

    status: boolean;

    notes: string;
};

type Errors = Partial<
    Record<
        keyof PayrollSettingFormData,
        string
    >
>;

type Props = {
    data:
        PayrollSettingFormData;

    setData: <
        K extends keyof PayrollSettingFormData,
    >(
        key: K,
        value:
            PayrollSettingFormData[K]
    ) => void;

    errors: Errors;

    processing: boolean;

    onSubmit: (
        event: FormEvent<HTMLFormElement>
    ) => void;
};

export default function PayrollSettingForm({
    data,
    setData,
    errors,
    processing,
    onSubmit,
}: Props) {
    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-800";

    const handleAttendanceIntegration =
        (
            enabled: boolean
        ) => {
            setData(
                "attendance_integration_enabled",
                enabled
            );

            if (!enabled) {
                setData(
                    "require_finalized_attendance",
                    false
                );

                setData(
                    "attendance_deduction_enabled",
                    false
                );

                setData(
                    "unpaid_leave_deduction_enabled",
                    false
                );

                setData(
                    "overtime_enabled",
                    false
                );

                setData(
                    "overtime_rate_multiplier",
                    "0"
                );
            }
        };

    const handleOvertime =
        (
            enabled: boolean
        ) => {
            setData(
                "overtime_enabled",
                enabled
            );

            if (!enabled) {
                setData(
                    "overtime_rate_multiplier",
                    "0"
                );
            } else if (
                Number(
                    data.overtime_rate_multiplier
                ) === 0
            ) {
                setData(
                    "overtime_rate_multiplier",
                    "1.5"
                );
            }
        };

    return (
        <form
            onSubmit={
                onSubmit
            }
            className="space-y-6"
        >
            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="mb-5">
                    <h2 className="text-base font-semibold">
                        General Payroll
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Configure payroll
                        frequency, currency,
                        salary proration,
                        and payment defaults.
                    </p>
                </div>

                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <Field
                        label="Pay Frequency"
                        error={
                            errors.pay_frequency
                        }
                        required
                    >
                        <select
                            value={
                                data.pay_frequency
                            }
                            onChange={(
                                event
                            ) => {
                                const value =
                                    event.target
                                        .value as PayrollSettingFormData[
                                        "pay_frequency"
                                    ];

                                setData(
                                    "pay_frequency",
                                    value
                                );

                                if (
                                    value !==
                                    "monthly"
                                ) {
                                    setData(
                                        "default_pay_day",
                                        ""
                                    );
                                }
                            }}
                            className={
                                inputClass
                            }
                        >
                            <option value="monthly">
                                Monthly
                            </option>

                            <option value="biweekly">
                                Biweekly
                            </option>

                            <option value="weekly">
                                Weekly
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Salary Proration Basis"
                        error={
                            errors.salary_proration_basis
                        }
                        required
                    >
                        <select
                            value={
                                data.salary_proration_basis
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "salary_proration_basis",
                                    event.target
                                        .value as PayrollSettingFormData[
                                        "salary_proration_basis"
                                    ]
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="calendar_days">
                                Calendar Days
                            </option>

                            <option value="working_days">
                                Working Days
                            </option>

                            <option value="fixed_30_days">
                                Fixed 30 Days
                            </option>
                        </select>

                        <p className="mt-1.5 text-xs leading-5 text-slate-500">
                            Used later when
                            salary must be
                            prorated for a
                            partial payroll
                            period.
                        </p>
                    </Field>

                    <Field
                        label="Default Currency"
                        error={
                            errors.default_currency
                        }
                        required
                    >
                        <input
                            type="text"
                            maxLength={
                                3
                            }
                            value={
                                data.default_currency
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "default_currency",
                                    event.target.value.toUpperCase()
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    {data.pay_frequency ===
                        "monthly" && (
                        <Field
                            label="Default Pay Day"
                            error={
                                errors.default_pay_day
                            }
                        >
                            <input
                                type="number"
                                min="1"
                                max="31"
                                value={
                                    data.default_pay_day
                                }
                                onChange={(
                                    event
                                ) =>
                                    setData(
                                        "default_pay_day",
                                        event.target
                                            .value
                                    )
                                }
                                className={
                                    inputClass
                                }
                            />

                            <p className="mt-1.5 text-xs leading-5 text-slate-500">
                                Preferred day
                                of the month.
                                A future payroll
                                period service
                                will handle
                                shorter months.
                            </p>
                        </Field>
                    )}

                    <Field
                        label="Rounding Method"
                        error={
                            errors.rounding_method
                        }
                        required
                    >
                        <select
                            value={
                                data.rounding_method
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "rounding_method",
                                    event.target
                                        .value as PayrollSettingFormData[
                                        "rounding_method"
                                    ]
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="none">
                                No Additional Rounding
                            </option>

                            <option value="nearest">
                                Round to Nearest
                            </option>

                            <option value="up">
                                Always Round Up
                            </option>

                            <option value="down">
                                Always Round Down
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Rounding Precision"
                        error={
                            errors.rounding_precision
                        }
                        required
                    >
                        <select
                            value={
                                data.rounding_precision
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "rounding_precision",
                                    event.target
                                        .value as
                                        | "0"
                                        | "2"
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="2">
                                2 Decimal Places
                            </option>

                            <option value="0">
                                Whole Amount
                            </option>
                        </select>
                    </Field>
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div>
                    <h2 className="text-base font-semibold">
                        Attendance & Leave
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Control how payroll
                        will consume your
                        existing Attendance
                        and Leave modules.
                    </p>
                </div>

                <div className="mt-5 space-y-4">
                    <Toggle
                        label="Attendance Integration"
                        description="Allow payroll calculations to consume attendance, leave and overtime information."
                        checked={
                            data.attendance_integration_enabled
                        }
                        onChange={
                            handleAttendanceIntegration
                        }
                    />

                    <div
                        className={`grid gap-4 md:grid-cols-2 ${
                            !data.attendance_integration_enabled
                                ? "pointer-events-none opacity-50"
                                : ""
                        }`}
                    >
                        <Toggle
                            label="Require Finalized Attendance"
                            description="Prevent attendance-dependent payroll from proceeding until the relevant attendance period is finalized."
                            checked={
                                data.require_finalized_attendance
                            }
                            onChange={(
                                value
                            ) =>
                                setData(
                                    "require_finalized_attendance",
                                    value
                                )
                            }
                        />

                        <Toggle
                            label="Attendance Deduction"
                            description="Allow payroll to calculate salary deductions from qualifying attendance shortages or absences."
                            checked={
                                data.attendance_deduction_enabled
                            }
                            onChange={(
                                value
                            ) =>
                                setData(
                                    "attendance_deduction_enabled",
                                    value
                                )
                            }
                        />

                        <Toggle
                            label="Unpaid Leave Deduction"
                            description="Allow approved unpaid leave to reduce salary during payroll calculation."
                            checked={
                                data.unpaid_leave_deduction_enabled
                            }
                            onChange={(
                                value
                            ) =>
                                setData(
                                    "unpaid_leave_deduction_enabled",
                                    value
                                )
                            }
                        />
                    </div>
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div>
                    <h2 className="text-base font-semibold">
                        Overtime
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Define whether
                        approved attendance
                        overtime can contribute
                        to payroll.
                    </p>
                </div>

                <div className="mt-5 grid gap-5 md:grid-cols-2">
                    <Toggle
                        label="Overtime Enabled"
                        description="Include eligible overtime when payroll calculations are performed."
                        checked={
                            data.overtime_enabled
                        }
                        disabled={
                            !data.attendance_integration_enabled
                        }
                        onChange={
                            handleOvertime
                        }
                    />

                    <Field
                        label="Overtime Rate Multiplier"
                        error={
                            errors.overtime_rate_multiplier
                        }
                    >
                        <input
                            type="number"
                            min="0"
                            step="0.0001"
                            disabled={
                                !data.attendance_integration_enabled ||
                                !data.overtime_enabled
                            }
                            value={
                                data.overtime_rate_multiplier
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "overtime_rate_multiplier",
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        />

                        <p className="mt-1.5 text-xs leading-5 text-slate-500">
                            Example: 1.5 means
                            one overtime hour
                            is paid at 1.5× the
                            future calculated
                            hourly salary rate.
                        </p>
                    </Field>
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <h2 className="text-base font-semibold">
                    Payroll Behaviour
                </h2>

                <div className="mt-5 grid gap-4 md:grid-cols-2">
                    <Toggle
                        label="Auto Include Recurring Components"
                        description="Automatically include recurring components from the employee's effective salary structure."
                        checked={
                            data.auto_include_recurring_components
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "auto_include_recurring_components",
                                value
                            )
                        }
                    />

                    <Toggle
                        label="Payroll Active"
                        description="Enable payroll processing for this company."
                        checked={
                            data.status
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "status",
                                value
                            )
                        }
                    />
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <Field
                    label="Notes"
                    error={
                        errors.notes
                    }
                >
                    <textarea
                        value={
                            data.notes
                        }
                        rows={4}
                        onChange={(
                            event
                        ) =>
                            setData(
                                "notes",
                                event.target
                                    .value
                            )
                        }
                        placeholder="Optional internal payroll policy notes..."
                        className="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950"
                    />
                </Field>
            </section>

            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={
                        processing
                    }
                    className="inline-flex h-10 items-center justify-center rounded-md bg-slate-900 px-5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                >
                    {processing
                        ? "Saving..."
                        : "Save Payroll Settings"}
                </button>
            </div>
        </form>
    );
}

function Field({
    label,
    error,
    required = false,
    children,
}: {
    label: string;
    error?: string;
    required?: boolean;
    children: ReactNode;
}) {
    return (
        <div>
            <label className="mb-2 block text-sm font-medium">
                {label}

                {required && (
                    <span className="ml-1 text-red-500">
                        *
                    </span>
                )}
            </label>

            {children}

            {error && (
                <p className="mt-1.5 text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

function Toggle({
    label,
    description,
    checked,
    disabled = false,
    onChange,
}: {
    label: string;
    description: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (
        value: boolean
    ) => void;
}) {
    return (
        <label
            className={`flex gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800 ${
                disabled
                    ? "cursor-not-allowed opacity-50"
                    : "cursor-pointer"
            }`}
        >
            <input
                type="checkbox"
                checked={
                    checked
                }
                disabled={
                    disabled
                }
                onChange={(
                    event
                ) =>
                    onChange(
                        event.target
                            .checked
                    )
                }
                className="mt-1 h-4 w-4 rounded border-slate-300"
            />

            <span>
                <span className="block text-sm font-medium">
                    {label}
                </span>

                <span className="mt-1 block text-xs leading-5 text-slate-500">
                    {description}
                </span>
            </span>
        </label>
    );
}