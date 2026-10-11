import type {
    FormEvent,
    ReactNode,
} from "react";

export type Company = {
    id: number;
    name: string;
};

export type LeadSourceFormData = {
    company_id: string;
    name: string;
    code: string;
    description: string;
    sort_order: string;
    status: boolean;
};

type Errors = Record<
    string,
    string | undefined
>;

type Props = {
    data: LeadSourceFormData;

    setData: <
        K extends keyof LeadSourceFormData,
    >(
        key: K,
        value: LeadSourceFormData[K]
    ) => void;

    companies: Company[];

    errors: Errors;

    processing: boolean;

    submitLabel: string;

    onSubmit: (
        event: FormEvent<HTMLFormElement>
    ) => void;
};

export default function LeadSourceForm({
    data,
    setData,
    companies,
    errors,
    processing,
    submitLabel,
    onSubmit,
}: Props) {
    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-800";

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="mb-5">
                    <h2 className="text-base font-semibold">
                        Lead Source
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Define where CRM
                        leads originate
                        from.
                    </p>
                </div>

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
                        label="Source Name"
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
                            placeholder="e.g. Website"
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
                            placeholder="e.g. WEBSITE"
                            className={
                                inputClass
                            }
                        />
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

                <div className="mt-5">
                    <Toggle
                        label="Active"
                        description="Allow this source to be selected for new leads."
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
                        placeholder="Optional description..."
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
                    className="inline-flex h-10 items-center justify-center rounded-md bg-slate-900 px-5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-950"
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