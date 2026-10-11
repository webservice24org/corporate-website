import type {
    FormEvent,
    ReactNode,
} from "react";
import {
    useMemo,
} from "react";

export type Company = {
    id: number;
    name: string;
};

export type PayrollSetting = {
    company_id: number;

    pay_frequency:
        | "monthly"
        | "biweekly"
        | "weekly";

    default_currency:
        string;

    default_pay_day:
        number | null;
};

export type PayrollPeriodFormData = {
    company_id:
        string;

    name:
        string;

    code:
        string;

    start_date:
        string;

    end_date:
        string;

    payment_date:
        string;

    status:
        | "draft"
        | "open"
        | "closed";

    notes:
        string;
};

type Errors =
    Record<
        string,
        string | undefined
    >;

type Props = {
    data:
        PayrollPeriodFormData;

    setData: <
        K extends keyof PayrollPeriodFormData,
    >(
        key: K,
        value:
            PayrollPeriodFormData[K]
    ) => void;

    companies:
        Company[];

    payrollSettings:
        PayrollSetting[];

    errors:
        Errors;

    processing:
        boolean;

    submitLabel:
        string;

    onSubmit: (
        event: FormEvent<HTMLFormElement>
    ) => void;
};

export default function PayrollPeriodForm({
    data,
    setData,
    companies,
    payrollSettings,
    errors,
    processing,
    submitLabel,
    onSubmit,
}: Props) {
    const selectedSetting =
        useMemo(
            () =>
                payrollSettings.find(
                    (
                        setting
                    ) =>
                        String(
                            setting.company_id
                        ) ===
                        data.company_id
                ) ?? null,
            [
                payrollSettings,
                data.company_id,
            ]
        );

    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-800";

    return (
        <form
            onSubmit={
                onSubmit
            }
            className="space-y-6"
        >
            {errors.period && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
                    {
                        errors.period
                    }
                </div>
            )}

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="mb-5">
                    <h2 className="text-base font-semibold">
                        Payroll Period
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Define the date range
                        and payment date for
                        this payroll cycle.
                    </p>
                </div>

                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <Field
                        label="Company"
                        error={
                            errors.company_id
                        }
                        required
                    >
                        <select
                            value={
                                data.company_id
                            }
                            onChange={(
                                event
                            ) => {
                                setData(
                                    "company_id",
                                    event.target
                                        .value
                                );
                            }}
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select company
                            </option>

                            {companies.map(
                                (
                                    company
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
                                )
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Period Name"
                        error={
                            errors.name
                        }
                        required
                    >
                        <input
                            type="text"
                            value={
                                data.name
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "name",
                                    event.target
                                        .value
                                )
                            }
                            placeholder="e.g. September 2026"
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Period Code"
                        error={
                            errors.code
                        }
                        required
                    >
                        <input
                            type="text"
                            value={
                                data.code
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "code",
                                    event.target.value.toUpperCase()
                                )
                            }
                            placeholder="e.g. PAY-2026-09"
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Start Date"
                        error={
                            errors.start_date
                        }
                        required
                    >
                        <input
                            type="date"
                            value={
                                data.start_date
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "start_date",
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        />

                        {errors.start_date && (
                            <p className="mt-2 rounded-md bg-red-50 px-3 py-2 text-xs leading-5 text-red-700 dark:bg-red-950/30 dark:text-red-300">
                                {
                                    errors.start_date
                                }
                            </p>
                        )}
                    </Field>

                    <Field
                        label="End Date"
                        error={
                            errors.end_date
                        }
                        required
                    >
                        <input
                            type="date"
                            value={
                                data.end_date
                            }
                            min={
                                data.start_date ||
                                undefined
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
                    </Field>

                    <Field
                        label="Payment Date"
                        error={
                            errors.payment_date
                        }
                    >
                        <input
                            type="date"
                            value={
                                data.payment_date
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "payment_date",
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        />

                        <p className="mt-1.5 text-xs text-slate-500">
                            Payment may be
                            made after the
                            payroll period
                            ends.
                        </p>
                    </Field>

                    <Field
                        label="Status"
                        error={
                            errors.status
                        }
                        required
                    >
                        <select
                            value={
                                data.status
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "status",
                                    event.target
                                        .value as
                                        | "draft"
                                        | "open"
                                        | "closed"
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="draft">
                                Draft
                            </option>

                            <option value="open">
                                Open
                            </option>

                            <option value="closed">
                                Closed
                            </option>
                        </select>
                    </Field>
                </div>

                {selectedSetting && (
                    <div className="mt-6 grid gap-3 rounded-lg bg-slate-50 p-4 text-sm md:grid-cols-3 dark:bg-slate-900/50">
                        <Info
                            label="Pay Frequency"
                            value={
                                selectedSetting.pay_frequency ===
                                "biweekly"
                                    ? "Biweekly"
                                    : selectedSetting.pay_frequency ===
                                      "weekly"
                                    ? "Weekly"
                                    : "Monthly"
                            }
                        />

                        <Info
                            label="Currency"
                            value={
                                selectedSetting.default_currency
                            }
                        />

                        <Info
                            label="Default Pay Day"
                            value={
                                selectedSetting.default_pay_day
                                    ? `Day ${selectedSetting.default_pay_day}`
                                    : "By period"
                            }
                        />
                    </div>
                )}
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
                        onChange={(
                            event
                        ) =>
                            setData(
                                "notes",
                                event.target
                                    .value
                            )
                        }
                        rows={4}
                        placeholder="Optional payroll period notes..."
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
                        : submitLabel}
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

            {error &&
                label !==
                    "Start Date" && (
                    <p className="mt-1.5 text-sm text-red-600">
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
    value: string;
}) {
    return (
        <div>
            <div className="text-xs text-slate-500">
                {label}
            </div>

            <div className="mt-1 font-medium">
                {value}
            </div>
        </div>
    );
}