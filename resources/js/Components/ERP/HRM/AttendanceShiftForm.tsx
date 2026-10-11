import type {
    FormEvent,
    ReactNode,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

export type AttendanceShiftFormData = {
    company_id: string;

    name: string;
    code: string;

    start_time: string;
    end_time: string;

    crosses_midnight: boolean;

    check_in_window_before_minutes:
        number;

    check_in_window_after_minutes:
        number;

    check_out_window_before_minutes:
        number;

    check_out_window_after_minutes:
        number;

    grace_minutes: number;

    early_leave_grace_minutes:
        number;

    minimum_work_minutes:
        number;

    half_day_minutes:
        number;

    overtime_after_minutes:
        number;

    break_minutes: number;

    break_is_paid: boolean;

    working_days: number[];

    status: string;

    notes: string;
};

type Props = {
    data:
        AttendanceShiftFormData;

    setData: <
        K extends keyof AttendanceShiftFormData,
    >(
        key: K,
        value:
            AttendanceShiftFormData[K],
    ) => void;

    errors: Partial<
        Record<
            keyof AttendanceShiftFormData,
            string
        >
    >;

    companies: Company[];

    processing: boolean;

    submitLabel: string;

    onSubmit: (
        event:
            FormEvent<HTMLFormElement>,
    ) => void;
};

const DAYS = [
    {
        value: 0,
        label: "Sunday",
    },
    {
        value: 1,
        label: "Monday",
    },
    {
        value: 2,
        label: "Tuesday",
    },
    {
        value: 3,
        label: "Wednesday",
    },
    {
        value: 4,
        label: "Thursday",
    },
    {
        value: 5,
        label: "Friday",
    },
    {
        value: 6,
        label: "Saturday",
    },
];

export default function AttendanceShiftForm({
    data,
    setData,
    errors,
    companies,
    processing,
    submitLabel,
    onSubmit,
}: Props) {
    const toggleDay = (
        day: number,
    ) => {
        if (
            data.working_days.includes(
                day,
            )
        ) {
            setData(
                "working_days",
                data.working_days.filter(
                    (
                        item,
                    ) =>
                        item !==
                        day,
                ),
            );

            return;
        }

        setData(
            "working_days",
            [
                ...data.working_days,
                day,
            ].sort(),
        );
    };

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <Card
                title="Basic Shift"
                description="Define the employee's scheduled working period."
            >
                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="Company"
                        required
                        error={
                            errors.company_id
                        }
                    >
                        <select
                            value={
                                data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "company_id",
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
                        label="Shift Name"
                        required
                        error={
                            errors.name
                        }
                    >
                        <input
                            value={
                                data.name
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "name",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Shift Code"
                        required
                        error={
                            errors.code
                        }
                    >
                        <input
                            value={
                                data.code
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "code",
                                    event.target.value.toUpperCase(),
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Start Time"
                        required
                        error={
                            errors.start_time
                        }
                    >
                        <input
                            type="time"
                            value={
                                data.start_time
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "start_time",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="End Time"
                        required
                        error={
                            errors.end_time
                        }
                    >
                        <input
                            type="time"
                            value={
                                data.end_time
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "end_time",
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
                    <Toggle
                        label="Shift crosses midnight"
                        description="Enable for shifts such as 10:00 PM to 6:00 AM."
                        checked={
                            data.crosses_midnight
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "crosses_midnight",
                                value,
                            )
                        }
                    />
                </div>
            </Card>

            <Card
                title="Working Days"
                description="Days on which this shift normally operates."
            >
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {DAYS.map(
                        (
                            day,
                        ) => (
                            <label
                                key={
                                    day.value
                                }
                                className="flex items-center gap-3 rounded-lg border p-3"
                            >
                                <input
                                    type="checkbox"
                                    checked={data.working_days.includes(
                                        day.value,
                                    )}
                                    onChange={() =>
                                        toggleDay(
                                            day.value,
                                        )
                                    }
                                />

                                <span className="text-sm font-medium text-slate-700">
                                    {
                                        day.label
                                    }
                                </span>
                            </label>
                        ),
                    )}
                </div>

                {errors.working_days && (
                    <p className="mt-2 text-sm text-red-600">
                        {
                            errors.working_days
                        }
                    </p>
                )}
            </Card>

            <Card
                title="Punch Windows"
                description="Controls which biometric punches can belong to this shift."
            >
                <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                    <MinutesField
                        label="Check-in Before"
                        value={
                            data.check_in_window_before_minutes
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "check_in_window_before_minutes",
                                value,
                            )
                        }
                    />

                    <MinutesField
                        label="Check-in After"
                        value={
                            data.check_in_window_after_minutes
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "check_in_window_after_minutes",
                                value,
                            )
                        }
                    />

                    <MinutesField
                        label="Check-out Before"
                        value={
                            data.check_out_window_before_minutes
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "check_out_window_before_minutes",
                                value,
                            )
                        }
                    />

                    <MinutesField
                        label="Check-out After"
                        value={
                            data.check_out_window_after_minutes
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "check_out_window_after_minutes",
                                value,
                            )
                        }
                    />
                </div>
            </Card>

            <Card
                title="Attendance Rules"
                description="Shift-level overrides used during attendance calculation."
            >
                <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    <MinutesField
                        label="Late Grace"
                        value={
                            data.grace_minutes
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "grace_minutes",
                                value,
                            )
                        }
                    />

                    <MinutesField
                        label="Early Leave Grace"
                        value={
                            data.early_leave_grace_minutes
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "early_leave_grace_minutes",
                                value,
                            )
                        }
                    />

                    <MinutesField
                        label="Minimum Work"
                        value={
                            data.minimum_work_minutes
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "minimum_work_minutes",
                                value,
                            )
                        }
                    />

                    <MinutesField
                        label="Half Day"
                        value={
                            data.half_day_minutes
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "half_day_minutes",
                                value,
                            )
                        }
                    />

                    <MinutesField
                        label="Overtime After"
                        value={
                            data.overtime_after_minutes
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "overtime_after_minutes",
                                value,
                            )
                        }
                    />

                    <MinutesField
                        label="Break Duration"
                        value={
                            data.break_minutes
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "break_minutes",
                                value,
                            )
                        }
                    />
                </div>

                <div className="mt-5">
                    <Toggle
                        label="Paid break"
                        description="If enabled, break time remains part of paid working minutes."
                        checked={
                            data.break_is_paid
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "break_is_paid",
                                value,
                            )
                        }
                    />
                </div>
            </Card>

            <Card
                title="Notes"
                description="Optional internal information about this shift."
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

function MinutesField({
    label,
    value,
    onChange,
}: {
    label: string;
    value: number;
    onChange: (
        value: number,
    ) => void;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
                {label}
            </label>

            <div className="flex">
                <input
                    type="number"
                    min={0}
                    value={value}
                    onChange={(
                        event,
                    ) =>
                        onChange(
                            Number(
                                event.target.value,
                            ),
                        )
                    }
                    className="min-h-10 min-w-0 flex-1 rounded-l-lg border border-slate-200 px-3 text-sm"
                />

                <span className="flex items-center rounded-r-lg border border-l-0 border-slate-200 bg-slate-50 px-3 text-xs text-slate-500">
                    min
                </span>
            </div>
        </div>
    );
}

function Toggle({
    label,
    description,
    checked,
    onChange,
}: {
    label: string;
    description?: string;
    checked: boolean;
    onChange: (
        value: boolean,
    ) => void;
}) {
    return (
        <label className="flex items-start gap-3 rounded-lg border p-4">
            <input
                type="checkbox"
                checked={checked}
                onChange={(
                    event,
                ) =>
                    onChange(
                        event.target.checked,
                    )
                }
                className="mt-1 size-4"
            />

            <span>
                <span className="block text-sm font-medium text-slate-800">
                    {label}
                </span>

                {description && (
                    <span className="mt-1 block text-xs text-slate-500">
                        {
                            description
                        }
                    </span>
                )}
            </span>
        </label>
    );
}

const inputClass =
    "block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200";