import type { FormEvent, ReactNode } from "react";
import { useMemo } from "react";

type Company = {
    id: number;
    name: string;
};

type BaseComponent = {
    id: number;
    company_id: number;
    name: string;
    code: string;
    component_type: "earning" | "deduction";
};

export type SalaryComponentFormData = {
    company_id: string;
    name: string;
    code: string;

    component_type:
        | "earning"
        | "deduction";

    calculation_type:
        | "fixed"
        | "percentage";

    percentage_of_component_id:
        string;

    default_amount: string;
    default_percentage: string;

    is_taxable: boolean;
    affects_gross_salary: boolean;
    is_recurring: boolean;
    status: boolean;

    sort_order: string;
    description: string;
};

type Errors = Partial<
    Record<
        keyof SalaryComponentFormData,
        string
    >
> & {
    percentage_of_component_id?: string;
};

type Props = {
    data: SalaryComponentFormData;

    setData: (
        key: keyof SalaryComponentFormData,
        value: string | boolean
    ) => void;

    errors: Errors;

    processing: boolean;

    companies: Company[];
    baseComponents: BaseComponent[];

    submitLabel: string;

    onSubmit: (
        event: FormEvent<HTMLFormElement>
    ) => void;
};

export default function SalaryComponentForm({
    data,
    setData,
    errors,
    processing,
    companies,
    baseComponents,
    submitLabel,
    onSubmit,
}: Props) {
    const filteredBaseComponents =
        useMemo(() => {
            if (!data.company_id) {
                return [];
            }

            return baseComponents.filter(
                (component) =>
                    String(
                        component.company_id
                    ) ===
                    data.company_id
            );
        }, [
            baseComponents,
            data.company_id,
        ]);

    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-800";

    const textareaClass =
        "min-h-28 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-800";

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <div className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="grid gap-5 md:grid-cols-2">
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
                            onChange={(event) => {
                                setData(
                                    "company_id",
                                    event.target.value
                                );

                                setData(
                                    "percentage_of_component_id",
                                    ""
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
                        label="Component Name"
                        error={errors.name}
                        required
                    >
                        <input
                            type="text"
                            value={data.name}
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "name",
                                    event.target
                                        .value
                                )
                            }
                            placeholder="e.g. Basic Salary"
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Code"
                        error={errors.code}
                        required
                    >
                        <input
                            type="text"
                            value={data.code}
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "code",
                                    event.target.value.toUpperCase()
                                )
                            }
                            placeholder="e.g. BASIC"
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Component Type"
                        error={
                            errors.component_type
                        }
                        required
                    >
                        <select
                            value={
                                data.component_type
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "component_type",
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="earning">
                                Earning
                            </option>

                            <option value="deduction">
                                Deduction
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Calculation Type"
                        error={
                            errors.calculation_type
                        }
                        required
                    >
                        <select
                            value={
                                data.calculation_type
                            }
                            onChange={(event) => {
                                const value =
                                    event.target
                                        .value as
                                        | "fixed"
                                        | "percentage";

                                setData(
                                    "calculation_type",
                                    value
                                );

                                if (
                                    value === "fixed"
                                ) {
                                    setData(
                                        "percentage_of_component_id",
                                        ""
                                    );

                                    setData(
                                        "default_percentage",
                                        "0"
                                    );
                                } else {
                                    setData(
                                        "default_amount",
                                        "0"
                                    );
                                }
                            }}
                            className={
                                inputClass
                            }
                        >
                            <option value="fixed">
                                Fixed Amount
                            </option>

                            <option value="percentage">
                                Percentage of Component
                            </option>
                        </select>
                    </Field>

                    {data.calculation_type ===
                        "fixed" && (
                        <Field
                            label="Default Amount"
                            error={
                                errors.default_amount
                            }
                        >
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={
                                    data.default_amount
                                }
                                onChange={(
                                    event
                                ) =>
                                    setData(
                                        "default_amount",
                                        event.target
                                            .value
                                    )
                                }
                                className={
                                    inputClass
                                }
                            />
                        </Field>
                    )}

                    {data.calculation_type ===
                        "percentage" && (
                        <>
                            <Field
                                label="Percentage Of"
                                error={
                                    errors.percentage_of_component_id
                                }
                                required
                            >
                                <select
                                    value={
                                        data.percentage_of_component_id
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "percentage_of_component_id",
                                            event
                                                .target
                                                .value
                                        )
                                    }
                                    className={
                                        inputClass
                                    }
                                >
                                    <option value="">
                                        Select base component
                                    </option>

                                    {filteredBaseComponents.map(
                                        (
                                            component
                                        ) => (
                                            <option
                                                key={
                                                    component.id
                                                }
                                                value={
                                                    component.id
                                                }
                                            >
                                                {
                                                    component.code
                                                }{" "}
                                                —{" "}
                                                {
                                                    component.name
                                                }
                                            </option>
                                        )
                                    )}
                                </select>
                            </Field>

                            <Field
                                label="Default Percentage"
                                error={
                                    errors.default_percentage
                                }
                                required
                            >
                                <div className="relative">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.0001"
                                        value={
                                            data.default_percentage
                                        }
                                        onChange={(
                                            event
                                        ) =>
                                            setData(
                                                "default_percentage",
                                                event
                                                    .target
                                                    .value
                                            )
                                        }
                                        className={`${inputClass} pr-10`}
                                    />

                                    <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-500">
                                        %
                                    </span>
                                </div>
                            </Field>
                        </>
                    )}

                    <Field
                        label="Sort Order"
                        error={
                            errors.sort_order
                        }
                    >
                        <input
                            type="number"
                            min="0"
                            value={
                                data.sort_order
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "sort_order",
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>
                </div>
            </div>

            <div className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <h2 className="text-base font-semibold">
                    Payroll Behaviour
                </h2>

                <p className="mt-1 text-sm text-slate-500">
                    These settings control how
                    this component will later
                    behave during payroll
                    calculation.
                </p>

                <div className="mt-5 grid gap-4 md:grid-cols-2">
                    <ToggleField
                        label="Taxable"
                        description="Include this earning when calculating taxable income."
                        checked={
                            data.is_taxable
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "is_taxable",
                                value
                            )
                        }
                    />

                    <ToggleField
                        label="Affects Gross Salary"
                        description="Include this component when gross salary is calculated."
                        checked={
                            data.affects_gross_salary
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "affects_gross_salary",
                                value
                            )
                        }
                    />

                    <ToggleField
                        label="Recurring"
                        description="Normally included in every applicable payroll period."
                        checked={
                            data.is_recurring
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "is_recurring",
                                value
                            )
                        }
                    />

                    <ToggleField
                        label="Active"
                        description="Only active components should be assignable to new salary structures."
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
            </div>

            <div className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <Field
                    label="Description"
                    error={
                        errors.description
                    }
                >
                    <textarea
                        value={
                            data.description
                        }
                        onChange={(
                            event
                        ) =>
                            setData(
                                "description",
                                event.target
                                    .value
                            )
                        }
                        placeholder="Optional notes about this salary component..."
                        className={
                            textareaClass
                        }
                    />
                </Field>
            </div>

            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex h-10 items-center justify-center rounded-md bg-slate-900 px-5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
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

            {error && (
                <p className="mt-1.5 text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

function ToggleField({
    label,
    description,
    checked,
    onChange,
}: {
    label: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex items-start justify-between rounded-lg border border-slate-200 p-4 transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900/50">
            <span className="pr-4">
                <span className="block text-sm font-medium">
                    {label}
                </span>
                <span className="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                    {description}
                </span>
            </span>

            <input
                type="checkbox"
                checked={checked}
                onChange={(e) =>
                    onChange(e.target.checked)
                }
                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-400 dark:border-slate-700 dark:bg-slate-900"
            />
        </label>
    );
}