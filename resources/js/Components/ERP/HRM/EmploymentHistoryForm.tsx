import {
    ReactNode,
    useMemo,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

type Branch = {
    id: number;
    company_id: number;
    name: string;
    code: string;
};

type Department = {
    id: number;
    company_id: number;
    branch_id:
        | number
        | null;
    name: string;
    code: string;
};

type Designation = {
    id: number;
    company_id: number;
    department_id:
        | number
        | null;
    name: string;
    code: string;
};

export type EmploymentHistoryFormData = {
    record_type: string;

    company_id: string;
    branch_id: string;
    department_id: string;
    designation_id: string;

    employer_name: string;
    branch_name: string;
    department_name: string;
    designation_name: string;

    employment_type: string;

    start_date: string;
    end_date: string;

    is_current: boolean;

    reason_for_leaving: string;
    notes: string;
};

type Props = {
    data:
        EmploymentHistoryFormData;

    setData: <
        K extends keyof EmploymentHistoryFormData,
    >(
        key: K,
        value:
            EmploymentHistoryFormData[K],
    ) => void;

    errors: Partial<
        Record<
            keyof EmploymentHistoryFormData,
            string
        >
    >;

    companies: Company[];
    branches: Branch[];
    departments: Department[];
    designations: Designation[];

    processing: boolean;
    submitLabel: string;

    onSubmit: (
        event:
            React.FormEvent<HTMLFormElement>,
    ) => void;
};

export default function EmploymentHistoryForm({
    data,
    setData,
    errors,
    companies,
    branches,
    departments,
    designations,
    processing,
    submitLabel,
    onSubmit,
}: Props) {
    const availableBranches =
        useMemo(
            () =>
                branches.filter(
                    (branch) =>
                        String(
                            branch.company_id,
                        ) ===
                        data.company_id,
                ),
            [
                branches,
                data.company_id,
            ],
        );

    const availableDepartments =
        useMemo(
            () =>
                departments.filter(
                    (
                        department,
                    ) =>
                        String(
                            department.company_id,
                        ) ===
                        data.company_id &&
                        (
                            department.branch_id ===
                                null ||
                            !data.branch_id ||
                            String(
                                department.branch_id,
                            ) ===
                                data.branch_id
                        ),
                ),
            [
                departments,
                data.company_id,
                data.branch_id,
            ],
        );

    const availableDesignations =
        useMemo(
            () =>
                designations.filter(
                    (
                        designation,
                    ) =>
                        String(
                            designation.company_id,
                        ) ===
                            data.company_id &&
                        (
                            designation.department_id ===
                                null ||
                            String(
                                designation.department_id,
                            ) ===
                                data.department_id
                        ),
                ),
            [
                designations,
                data.company_id,
                data.department_id,
            ],
        );

    const internal =
        data.record_type ===
        "internal";

    const changeCompany = (
        value: string,
    ) => {
        setData(
            "company_id",
            value,
        );

        setData(
            "branch_id",
            "",
        );

        setData(
            "department_id",
            "",
        );

        setData(
            "designation_id",
            "",
        );
    };

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <section className="rounded-xl border bg-white p-6 shadow-sm">
                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="History Type"
                        required
                        error={
                            errors.record_type
                        }
                    >
                        <select
                            value={
                                data.record_type
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "record_type",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="internal">
                                Internal
                            </option>

                            <option value="external">
                                External Previous Employment
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Employment Type"
                        error={
                            errors.employment_type
                        }
                    >
                        <select
                            value={
                                data.employment_type
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "employment_type",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Not specified
                            </option>

                            <option value="full_time">
                                Full Time
                            </option>

                            <option value="part_time">
                                Part Time
                            </option>

                            <option value="contract">
                                Contract
                            </option>

                            <option value="temporary">
                                Temporary
                            </option>

                            <option value="intern">
                                Intern
                            </option>
                        </select>
                    </Field>
                </div>

                {internal ? (
                    <div className="mt-5 grid gap-5 md:grid-cols-2">
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
                            label="Branch"
                            error={
                                errors.branch_id
                            }
                        >
                            <select
                                value={
                                    data.branch_id
                                }
                                onChange={(
                                    event,
                                ) => {
                                    setData(
                                        "branch_id",
                                        event.target.value,
                                    );

                                    setData(
                                        "department_id",
                                        "",
                                    );

                                    setData(
                                        "designation_id",
                                        "",
                                    );
                                }}
                                className={
                                    inputClass
                                }
                            >
                                <option value="">
                                    No branch
                                </option>

                                {availableBranches.map(
                                    (
                                        branch,
                                    ) => (
                                        <option
                                            key={
                                                branch.id
                                            }
                                            value={
                                                branch.id
                                            }
                                        >
                                            {
                                                branch.name
                                            }
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>

                        <Field
                            label="Department"
                            error={
                                errors.department_id
                            }
                        >
                            <select
                                value={
                                    data.department_id
                                }
                                onChange={(
                                    event,
                                ) => {
                                    setData(
                                        "department_id",
                                        event.target.value,
                                    );

                                    setData(
                                        "designation_id",
                                        "",
                                    );
                                }}
                                className={
                                    inputClass
                                }
                            >
                                <option value="">
                                    No department
                                </option>

                                {availableDepartments.map(
                                    (
                                        department,
                                    ) => (
                                        <option
                                            key={
                                                department.id
                                            }
                                            value={
                                                department.id
                                            }
                                        >
                                            {
                                                department.name
                                            }
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>

                        <Field
                            label="Designation"
                            error={
                                errors.designation_id
                            }
                        >
                            <select
                                value={
                                    data.designation_id
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setData(
                                        "designation_id",
                                        event.target.value,
                                    )
                                }
                                className={
                                    inputClass
                                }
                            >
                                <option value="">
                                    No designation
                                </option>

                                {availableDesignations.map(
                                    (
                                        designation,
                                    ) => (
                                        <option
                                            key={
                                                designation.id
                                            }
                                            value={
                                                designation.id
                                            }
                                        >
                                            {
                                                designation.name
                                            }
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>
                    </div>
                ) : (
                    <div className="mt-5 grid gap-5 md:grid-cols-2">
                        <TextField
                            label="Employer"
                            required
                            value={
                                data.employer_name
                            }
                            error={
                                errors.employer_name
                            }
                            onChange={(
                                value,
                            ) =>
                                setData(
                                    "employer_name",
                                    value,
                                )
                            }
                        />

                        <TextField
                            label="Designation"
                            required
                            value={
                                data.designation_name
                            }
                            error={
                                errors.designation_name
                            }
                            onChange={(
                                value,
                            ) =>
                                setData(
                                    "designation_name",
                                    value,
                                )
                            }
                        />

                        <TextField
                            label="Department"
                            value={
                                data.department_name
                            }
                            error={
                                errors.department_name
                            }
                            onChange={(
                                value,
                            ) =>
                                setData(
                                    "department_name",
                                    value,
                                )
                            }
                        />

                        <TextField
                            label="Branch / Location"
                            value={
                                data.branch_name
                            }
                            error={
                                errors.branch_name
                            }
                            onChange={(
                                value,
                            ) =>
                                setData(
                                    "branch_name",
                                    value,
                                )
                            }
                        />
                    </div>
                )}
            </section>

            <section className="rounded-xl border bg-white p-6 shadow-sm">
                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="Start Date"
                        required
                        error={
                            errors.start_date
                        }
                    >
                        <input
                            type="date"
                            value={
                                data.start_date
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "start_date",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="End Date"
                        error={
                            errors.end_date
                        }
                    >
                        <input
                            type="date"
                            value={
                                data.end_date
                            }
                            disabled={
                                data.is_current
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "end_date",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <label className="flex items-center gap-3 text-sm font-medium text-slate-700">
                        <input
                            type="checkbox"
                            checked={
                                data.is_current
                            }
                            onChange={(
                                event,
                            ) => {
                                setData(
                                    "is_current",
                                    event.target.checked,
                                );

                                if (
                                    event.target.checked
                                ) {
                                    setData(
                                        "end_date",
                                        "",
                                    );
                                }
                            }}
                        />

                        Current Employment
                    </label>
                </div>

                <div className="mt-5 grid gap-5">
                    <TextField
                        label="Reason for Leaving"
                        value={
                            data.reason_for_leaving
                        }
                        error={
                            errors.reason_for_leaving
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "reason_for_leaving",
                                value,
                            )
                        }
                    />

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
            </section>

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

function TextField({
    label,
    value,
    onChange,
    error,
    required = false,
}: {
    label: string;
    value: string;
    onChange: (
        value: string,
    ) => void;
    error?: string;
    required?: boolean;
}) {
    return (
        <Field
            label={label}
            required={
                required
            }
            error={error}
        >
            <input
                value={value}
                onChange={(
                    event,
                ) =>
                    onChange(
                        event.target.value,
                    )
                }
                className={
                    inputClass
                }
            />
        </Field>
    );
}

const inputClass =
    "block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200 disabled:bg-slate-100";