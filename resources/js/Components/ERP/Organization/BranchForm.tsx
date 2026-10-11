import type {
    FormEvent,
    ReactNode,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
    timezone?: string;
    country?: string | null;
};

export type BranchFormData = {
    company_id: string;
    name: string;
    code: string;
    email: string;
    phone: string;
    address: string;
    city: string;
    state: string;
    postal_code: string;
    country: string;
    timezone: string;
    status: string;
    is_default: boolean;
};

type Props = {
    companies: Company[];

    data: BranchFormData;

    errors: Partial<
        Record<
            keyof BranchFormData,
            string
        >
    >;

    processing: boolean;

    submitLabel: string;

    setData: <
        K extends keyof BranchFormData,
    >(
        key: K,
        value: BranchFormData[K],
    ) => void;

    onSubmit: (
        event: FormEvent<HTMLFormElement>,
    ) => void;
};

export default function BranchForm({
    companies,
    data,
    errors,
    processing,
    submitLabel,
    setData,
    onSubmit,
}: Props) {
    const handleCompanyChange = (
        companyId: string,
    ) => {
        setData(
            "company_id",
            companyId,
        );

        const company =
            companies.find(
                (item) =>
                    String(item.id) ===
                    companyId,
            );

        if (
            company?.timezone &&
            ! data.timezone
        ) {
            setData(
                "timezone",
                company.timezone,
            );
        }

        if (
            company?.country &&
            ! data.country
        ) {
            setData(
                "country",
                company.country,
            );
        }
    };

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <div className="grid gap-6 xl:grid-cols-3">
                <div className="space-y-6 xl:col-span-2">
                    <SectionCard
                        title="Branch Information"
                        description="Branch identity and parent company."
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
                                        handleCompanyChange(
                                            event
                                                .target
                                                .value,
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
                                label="Branch Name"
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
                                            event
                                                .target
                                                .value,
                                        )
                                    }
                                    className={
                                        inputClass
                                    }
                                />
                            </Field>

                            <Field
                                label="Branch Code"
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
                                            event
                                                .target
                                                .value.toUpperCase(),
                                        )
                                    }
                                    placeholder="DHA"
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
                                        event,
                                    ) =>
                                        setData(
                                            "email",
                                            event
                                                .target
                                                .value,
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
                                        event,
                                    ) =>
                                        setData(
                                            "phone",
                                            event
                                                .target
                                                .value,
                                        )
                                    }
                                    className={
                                        inputClass
                                    }
                                />
                            </Field>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Address"
                        description="Physical branch location."
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <div className="md:col-span-2">
                                <Field
                                    label="Address"
                                    error={
                                        errors.address
                                    }
                                >
                                    <textarea
                                        rows={4}
                                        value={
                                            data.address
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "address",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        className={
                                            inputClass
                                        }
                                    />
                                </Field>
                            </div>

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
                                        event,
                                    ) =>
                                        setData(
                                            "city",
                                            event
                                                .target
                                                .value,
                                        )
                                    }
                                    className={
                                        inputClass
                                    }
                                />
                            </Field>

                            <Field
                                label="State / Division"
                                error={
                                    errors.state
                                }
                            >
                                <input
                                    value={
                                        data.state
                                    }
                                    onChange={(
                                        event,
                                    ) =>
                                        setData(
                                            "state",
                                            event
                                                .target
                                                .value,
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
                                        event,
                                    ) =>
                                        setData(
                                            "postal_code",
                                            event
                                                .target
                                                .value,
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
                                        event,
                                    ) =>
                                        setData(
                                            "country",
                                            event
                                                .target
                                                .value,
                                        )
                                    }
                                    className={
                                        inputClass
                                    }
                                />
                            </Field>
                        </div>
                    </SectionCard>
                </div>

                <div className="space-y-6">
                    <SectionCard
                        title="Branch Settings"
                        description="Operational defaults for this branch."
                    >
                        <div className="space-y-5">
                            <Field
                                label="Timezone"
                                required
                                error={
                                    errors.timezone
                                }
                            >
                                <input
                                    value={
                                        data.timezone
                                    }
                                    onChange={(
                                        event,
                                    ) =>
                                        setData(
                                            "timezone",
                                            event
                                                .target
                                                .value,
                                        )
                                    }
                                    className={
                                        inputClass
                                    }
                                />
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
                                            event
                                                .target
                                                .value,
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

                            <label className="flex cursor-pointer items-start gap-3 rounded-lg border bg-slate-50 p-4">
                                <input
                                    type="checkbox"
                                    checked={
                                        data.is_default
                                    }
                                    onChange={(
                                        event,
                                    ) =>
                                        setData(
                                            "is_default",
                                            event
                                                .target
                                                .checked,
                                        )
                                    }
                                    className="mt-1 size-4"
                                />

                                <span>
                                    <span className="block text-sm font-semibold text-slate-900">
                                        Default Branch
                                    </span>

                                    <span className="mt-1 block text-xs leading-5 text-slate-500">
                                        Make this the
                                        default branch
                                        for its company.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </SectionCard>

                    <button
                        type="submit"
                        disabled={
                            processing
                        }
                        className="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {processing
                            ? "Saving..."
                            : submitLabel}
                    </button>
                </div>
            </div>
        </form>
    );
}

const inputClass = `
    w-full rounded-lg border border-slate-300
    bg-white px-3 py-2.5 text-sm text-slate-900
    outline-none transition
    focus:border-indigo-500
    focus:ring-2 focus:ring-indigo-500/20
`;

function Field({
    label,
    required = false,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    children: ReactNode;
}) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-medium text-slate-700">
                {label}

                {required && (
                    <span className="ml-1 text-red-500">
                        *
                    </span>
                )}
            </span>

            {children}

            {error && (
                <span className="mt-1.5 block text-xs text-red-600">
                    {error}
                </span>
            )}
        </label>
    );
}

function SectionCard({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-white shadow-sm">
            <div className="border-b px-5 py-4">
                <h3 className="font-semibold text-slate-950">
                    {title}
                </h3>

                {description && (
                    <p className="mt-1 text-sm text-slate-500">
                        {description}
                    </p>
                )}
            </div>

            <div className="p-5">
                {children}
            </div>
        </section>
    );
}