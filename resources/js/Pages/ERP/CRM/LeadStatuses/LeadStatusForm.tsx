import type {
    FormEvent,
    ReactNode,
} from "react";

export type Company = {
    id: number;
    name: string;
};

export type LeadStatusFormData = {
    company_id: string;

    name: string;

    code: string;

    probability: string;

    is_won: boolean;

    is_lost: boolean;

    description: string;

    sort_order: string;

    status: boolean;
};

type Errors = Record<
    string,
    string | undefined
>;

type Props = {
    data: LeadStatusFormData;

    setData: <
        K extends keyof LeadStatusFormData,
    >(
        key: K,
        value: LeadStatusFormData[K]
    ) => void;

    companies: Company[];

    errors: Errors;

    processing: boolean;

    submitLabel: string;

    onSubmit: (
        event: FormEvent<HTMLFormElement>
    ) => void;
};

export default function LeadStatusForm({
    data,
    setData,
    companies,
    errors,
    processing,
    submitLabel,
    onSubmit,
}: Props) {
    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950";

    const setWon = (
        checked: boolean
    ) => {
        setData(
            "is_won",
            checked
        );

        if (checked) {
            setData(
                "is_lost",
                false
            );

            setData(
                "probability",
                "100"
            );
        }
    };

    const setLost = (
        checked: boolean
    ) => {
        setData(
            "is_lost",
            checked
        );

        if (checked) {
            setData(
                "is_won",
                false
            );

            setData(
                "probability",
                "0"
            );
        }
    };

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="mb-5">
                    <h2 className="text-base font-semibold">
                        Lead Status
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Configure CRM
                        pipeline stages and
                        terminal Won/Lost
                        states.
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
                            ) =>
                                setData(
                                    "company_id",
                                    event.target
                                        .value
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
                        label="Status Name"
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
                            placeholder="e.g. Qualified"
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Code"
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
                            placeholder="e.g. QUALIFIED"
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Probability"
                        error={
                            errors.probability
                        }
                        required
                    >
                        <div className="relative">
                            <input
                                type="number"
                                min="0"
                                max="100"
                                value={
                                    data.probability
                                }
                                disabled={
                                    data.is_won ||
                                    data.is_lost
                                }
                                onChange={(
                                    event
                                ) =>
                                    setData(
                                        "probability",
                                        event.target
                                            .value
                                    )
                                }
                                className={
                                    inputClass
                                }
                            />

                            <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-500">
                                %
                            </span>
                        </div>
                    </Field>

                    <Field
                        label="Sort Order"
                        error={
                            errors.sort_order
                        }
                        required
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

                <div className="mt-5 grid gap-4 md:grid-cols-3">
                    <Toggle
                        label="Won Status"
                        description="Marks this as a successful terminal pipeline state."
                        checked={
                            data.is_won
                        }
                        onChange={
                            setWon
                        }
                    />

                    <Toggle
                        label="Lost Status"
                        description="Marks this as an unsuccessful terminal pipeline state."
                        checked={
                            data.is_lost
                        }
                        onChange={
                            setLost
                        }
                    />

                    <Toggle
                        label="Active"
                        description="Allow this status to be selected for leads."
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

                {errors.is_won && (
                    <p className="mt-3 text-sm text-red-600">
                        {
                            errors.is_won
                        }
                    </p>
                )}

                {errors.is_lost && (
                    <p className="mt-3 text-sm text-red-600">
                        {
                            errors.is_lost
                        }
                    </p>
                )}
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <Field
                    label="Description"
                    error={
                        errors.description
                    }
                >
                    <textarea
                        rows={4}
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
                        placeholder="Optional pipeline-stage description..."
                        className="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-800 dark:bg-slate-950"
                    />
                </Field>
            </section>

            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={
                        processing
                    }
                    className="h-10 rounded-md bg-slate-900 px-5 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
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

function Toggle({
    label,
    description,
    checked,
    onChange,
}: {
    label: string;
    description: string;
    checked: boolean;
    onChange: (
        value: boolean
    ) => void;
}) {
    return (
        <label className="flex cursor-pointer gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
            <input
                type="checkbox"
                checked={
                    checked
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