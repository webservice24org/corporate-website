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

export type LeadSource = {
    id: number;
    company_id: number;
    name: string;
    code: string;
    status: boolean;
};

export type LeadStatus = {
    id: number;
    company_id: number;
    name: string;
    code: string;
    probability: number;
    is_won: boolean;
    is_lost: boolean;
    status: boolean;
};

export type UserOption = {
    id: number;
    name: string;
    email: string;
};

export type LeadFormData = {
    company_id: string;
    lead_number: string;
    lead_source_id: string;
    lead_status_id: string;
    owner_user_id: string;

    lead_type:
        | "individual"
        | "company";

    company_name: string;
    contact_name: string;
    job_title: string;

    email: string;
    phone: string;
    alternate_phone: string;
    website: string;

    expected_value: string;
    currency: string;

    next_follow_up_at: string;

    address_line_1: string;
    address_line_2: string;
    city: string;
    state: string;
    postal_code: string;
    country: string;

    notes: string;

    is_active: boolean;
};

type Errors = Record<
    string,
    string | undefined
>;

type Props = {
    data: LeadFormData;

    setData: <
        K extends keyof LeadFormData,
    >(
        key: K,
        value: LeadFormData[K]
    ) => void;

    companies: Company[];

    sources: LeadSource[];

    statuses: LeadStatus[];

    users: UserOption[];

    errors: Errors;

    processing: boolean;

    submitLabel: string;

    onSubmit: (
        event: FormEvent<HTMLFormElement>
    ) => void;
};

export default function LeadForm({
    data,
    setData,
    companies,
    sources,
    statuses,
    users,
    errors,
    processing,
    submitLabel,
    onSubmit,
}: Props) {
    const companySources =
        useMemo(
            () =>
                sources.filter(
                    (source) =>
                        String(
                            source.company_id
                        ) ===
                        data.company_id
                ),
            [
                sources,
                data.company_id,
            ]
        );

    const companyStatuses =
        useMemo(
            () =>
                statuses.filter(
                    (status) =>
                        String(
                            status.company_id
                        ) ===
                        data.company_id
                ),
            [
                statuses,
                data.company_id,
            ]
        );

    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-800";

    const changeCompany = (
        companyId: string
    ) => {
        setData(
            "company_id",
            companyId
        );

        setData(
            "lead_source_id",
            ""
        );

        setData(
            "lead_status_id",
            ""
        );
    };

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="mb-5">
                    <h2 className="text-base font-semibold">
                        Lead Information
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Define the lead,
                        pipeline status,
                        source and owner.
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
                                changeCompany(
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
                                (company) => (
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
                        label="Lead Number"
                        error={
                            errors.lead_number
                        }
                        required
                    >
                        <input
                            type="text"
                            value={
                                data.lead_number
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "lead_number",
                                    event.target.value.toUpperCase()
                                )
                            }
                            placeholder="e.g. LEAD-0001"
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Lead Type"
                        error={
                            errors.lead_type
                        }
                        required
                    >
                        <select
                            value={
                                data.lead_type
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "lead_type",
                                    event.target
                                        .value as
                                        | "individual"
                                        | "company"
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="individual">
                                Individual
                            </option>

                            <option value="company">
                                Company
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Lead Source"
                        error={
                            errors.lead_source_id
                        }
                    >
                        <select
                            value={
                                data.lead_source_id
                            }
                            disabled={
                                !data.company_id
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "lead_source_id",
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                No source
                            </option>

                            {companySources.map(
                                (source) => (
                                    <option
                                        key={
                                            source.id
                                        }
                                        value={
                                            source.id
                                        }
                                    >
                                        {
                                            source.name
                                        }{" "}
                                        ({source.code})
                                    </option>
                                )
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Lead Status"
                        error={
                            errors.lead_status_id
                        }
                        required
                    >
                        <select
                            value={
                                data.lead_status_id
                            }
                            disabled={
                                !data.company_id
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "lead_status_id",
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select status
                            </option>

                            {companyStatuses.map(
                                (
                                    status
                                ) => (
                                    <option
                                        key={
                                            status.id
                                        }
                                        value={
                                            status.id
                                        }
                                    >
                                        {
                                            status.name
                                        }{" "}
                                        ({status.probability}%)
                                    </option>
                                )
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Owner"
                        error={
                            errors.owner_user_id
                        }
                    >
                        <select
                            value={
                                data.owner_user_id
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "owner_user_id",
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Unassigned
                            </option>

                            {users.map(
                                (user) => (
                                    <option
                                        key={
                                            user.id
                                        }
                                        value={
                                            user.id
                                        }
                                    >
                                        {
                                            user.name
                                        }
                                    </option>
                                )
                            )}
                        </select>
                    </Field>
                </div>

                <div className="mt-5">
                    <Toggle
                        label="Lead Active"
                        description="Inactive leads remain in CRM history but are excluded when filtering for active leads."
                        checked={
                            data.is_active
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "is_active",
                                value
                            )
                        }
                    />
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="mb-5">
                    <h2 className="text-base font-semibold">
                        Contact
                    </h2>
                </div>

                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    {data.lead_type ===
                        "company" && (
                        <Field
                            label="Company Name"
                            error={
                                errors.company_name
                            }
                            required
                        >
                            <input
                                value={
                                    data.company_name
                                }
                                onChange={(
                                    event
                                ) =>
                                    setData(
                                        "company_name",
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

                    <Field
                        label="Contact Name"
                        error={
                            errors.contact_name
                        }
                        required
                    >
                        <input
                            value={
                                data.contact_name
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "contact_name",
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
                        label="Job Title"
                        error={
                            errors.job_title
                        }
                    >
                        <input
                            value={
                                data.job_title
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "job_title",
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
                        label="Email"
                        error={
                            errors.email
                        }
                    >
                        <input
                            type="email"
                            value={
                                data.email
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "email",
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
                        label="Phone"
                        error={
                            errors.phone
                        }
                    >
                        <input
                            value={
                                data.phone
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "phone",
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
                        label="Alternate Phone"
                        error={
                            errors.alternate_phone
                        }
                    >
                        <input
                            value={
                                data.alternate_phone
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "alternate_phone",
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
                        label="Website"
                        error={
                            errors.website
                        }
                    >
                        <input
                            value={
                                data.website
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "website",
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
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="mb-5">
                    <h2 className="text-base font-semibold">
                        Commercial
                    </h2>
                </div>

                <div className="grid gap-5 md:grid-cols-3">
                    <Field
                        label="Expected Value"
                        error={
                            errors.expected_value
                        }
                        required
                    >
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={
                                data.expected_value
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "expected_value",
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
                        label="Currency"
                        error={
                            errors.currency
                        }
                        required
                    >
                        <input
                            maxLength={
                                3
                            }
                            value={
                                data.currency
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "currency",
                                    event.target.value.toUpperCase()
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Next Follow-up"
                        error={
                            errors.next_follow_up_at
                        }
                    >
                        <input
                            type="datetime-local"
                            value={
                                data.next_follow_up_at
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "next_follow_up_at",
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
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <h2 className="mb-5 text-base font-semibold">
                    Address
                </h2>

                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <Field
                        label="Address Line 1"
                        error={
                            errors.address_line_1
                        }
                    >
                        <input
                            value={
                                data.address_line_1
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "address_line_1",
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
                        label="Address Line 2"
                        error={
                            errors.address_line_2
                        }
                    >
                        <input
                            value={
                                data.address_line_2
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "address_line_2",
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
                        label="City"
                        error={
                            errors.city
                        }
                    >
                        <input
                            value={
                                data.city
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "city",
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
                        label="State / Region"
                        error={
                            errors.state
                        }
                    >
                        <input
                            value={
                                data.state
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "state",
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
                        label="Postal Code"
                        error={
                            errors.postal_code
                        }
                    >
                        <input
                            value={
                                data.postal_code
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "postal_code",
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
                        label="Country"
                        error={
                            errors.country
                        }
                    >
                        <input
                            value={
                                data.country
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "country",
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
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <Field
                    label="Notes"
                    error={
                        errors.notes
                    }
                >
                    <textarea
                        rows={5}
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
                checked={checked}
                onChange={(
                    event
                ) =>
                    onChange(
                        event.target
                            .checked
                    )
                }
                className="mt-1 h-4 w-4 rounded"
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