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

export type DepartmentFormData = {
    company_id: string;
    branch_id: string;
    name: string;
    code: string;
    description: string;
    status: string;
};

type DepartmentFormProps = {
    data: DepartmentFormData;

    setData: <K extends keyof DepartmentFormData>(
        key: K,
        value: DepartmentFormData[K],
    ) => void;

    errors: Partial<
        Record<
            keyof DepartmentFormData,
            string
        >
    >;

    companies: Company[];

    branches: Branch[];

    processing: boolean;

    submitLabel: string;

    onSubmit: (
        event: React.FormEvent<HTMLFormElement>,
    ) => void;

    footer?: ReactNode;
};

export default function DepartmentForm({
    data,
    setData,
    errors,
    companies,
    branches,
    processing,
    submitLabel,
    onSubmit,
    footer,
}: DepartmentFormProps) {
    const availableBranches =
        useMemo(
            () =>
                branches.filter(
                    (branch) =>
                        String(
                            branch.company_id,
                        ) ===
                        String(
                            data.company_id,
                        ),
                ),
            [
                branches,
                data.company_id,
            ],
        );

    const handleCompanyChange = (
        companyId: string,
    ) => {
        setData(
            "company_id",
            companyId,
        );

        /*
         * A branch from the previous
         * company must never remain
         * selected.
         */
        setData(
            "branch_id",
            "",
        );
    };

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <div className="rounded-xl border bg-white p-6 shadow-sm">
                <div className="mb-6">
                    <h2 className="text-lg font-semibold text-slate-900">
                        Department
                        Information
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Configure the
                        company and
                        optional branch
                        that owns this
                        department.
                    </p>
                </div>

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
                            className={inputClass}
                        >
                            <option value="">
                                Select
                                company
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
                        label="Branch"
                        error={
                            errors.branch_id
                        }
                        hint="Optional. Leave empty for a company-wide department."
                    >
                        <select
                            value={
                                data.branch_id
                            }
                            disabled={
                                ! data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "branch_id",
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
                                Company-wide
                                department
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
                                        }{" "}
                                        (
                                        {
                                            branch.code
                                        }
                                        )
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Department Name"
                        required
                        error={
                            errors.name
                        }
                    >
                        <input
                            type="text"
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
                            placeholder="Human Resources"
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Department Code"
                        required
                        error={
                            errors.code
                        }
                        hint="Unique within the selected company."
                    >
                        <input
                            type="text"
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
                                        .value
                                        .toUpperCase(),
                                )
                            }
                            placeholder="HR"
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
                </div>

                <div className="mt-5">
                    <Field
                        label="Description"
                        error={
                            errors.description
                        }
                    >
                        <textarea
                            rows={5}
                            value={
                                data.description
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "description",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            placeholder="Optional department description..."
                            className={
                                inputClass
                            }
                        />
                    </Field>
                </div>
            </div>

            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    {footer}
                </div>

                <button
                    type="submit"
                    disabled={
                        processing
                    }
                    className="inline-flex min-h-10 items-center justify-center rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {processing
                        ? "Saving..."
                        : submitLabel}
                </button>
            </div>
        </form>
    );
}

type FieldProps = {
    label: string;
    children: ReactNode;
    required?: boolean;
    error?: string;
    hint?: string;
};

function Field({
    label,
    children,
    required = false,
    error,
    hint,
}: FieldProps) {
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
                <p className="mt-1.5 text-sm text-red-600">
                    {error}
                </p>
            )}

            {! error &&
                hint && (
                    <p className="mt-1.5 text-xs text-slate-500">
                        {hint}
                    </p>
                )}
        </div>
    );
}

const inputClass =
    "block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-slate-400 focus:ring-2 focus:ring-slate-200 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500";