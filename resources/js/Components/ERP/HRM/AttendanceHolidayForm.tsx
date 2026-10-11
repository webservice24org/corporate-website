import { useMemo } from "react";

type Company = {
    id: number;
    name: string;
};

type Branch = {
    id: number;
    company_id: number;
    name: string;
};

export type HolidayFormData = {
    company_id: string;
    branch_id: string;
    name: string;
    holiday_date: string;
    holiday_type: string;
    is_paid: boolean;
    status: string;
    notes: string;
};

type Props = {
    data: HolidayFormData;
    setData: <K extends keyof HolidayFormData>(
        key: K,
        value: HolidayFormData[K]
    ) => void;
    errors: Partial<
        Record<
            keyof HolidayFormData,
            string
        >
    >;
    companies: Company[];
    branches: Branch[];
};

export default function AttendanceHolidayForm({
    data,
    setData,
    errors,
    companies,
    branches,
}: Props) {
    const availableBranches =
        useMemo(
            () =>
                branches.filter(
                    (branch) =>
                        !data.company_id ||
                        String(
                            branch.company_id
                        ) ===
                            String(
                                data.company_id
                            )
                ),
            [
                branches,
                data.company_id,
            ]
        );

    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-slate-400 dark:border-slate-800 dark:bg-slate-950";

    return (
        <div className="grid gap-5 md:grid-cols-2">
            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Company *
                </label>

                <select
                    value={data.company_id}
                    onChange={(event) => {
                        setData(
                            "company_id",
                            event.target.value
                        );

                        setData(
                            "branch_id",
                            ""
                        );
                    }}
                    className={inputClass}
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

                {errors.company_id && (
                    <p className="mt-1 text-xs text-red-600">
                        {
                            errors.company_id
                        }
                    </p>
                )}
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Branch
                </label>

                <select
                    value={data.branch_id}
                    onChange={(event) =>
                        setData(
                            "branch_id",
                            event.target.value
                        )
                    }
                    className={inputClass}
                >
                    <option value="">
                        Company-wide
                    </option>

                    {availableBranches.map(
                        (branch) => (
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
                        )
                    )}
                </select>

                {errors.branch_id && (
                    <p className="mt-1 text-xs text-red-600">
                        {
                            errors.branch_id
                        }
                    </p>
                )}
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Holiday Name *
                </label>

                <input
                    value={data.name}
                    onChange={(event) =>
                        setData(
                            "name",
                            event.target.value
                        )
                    }
                    className={inputClass}
                />

                {errors.name && (
                    <p className="mt-1 text-xs text-red-600">
                        {errors.name}
                    </p>
                )}
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Holiday Date *
                </label>

                <input
                    type="date"
                    value={
                        data.holiday_date
                    }
                    onChange={(event) =>
                        setData(
                            "holiday_date",
                            event.target.value
                        )
                    }
                    className={inputClass}
                />

                {errors.holiday_date && (
                    <p className="mt-1 text-xs text-red-600">
                        {
                            errors.holiday_date
                        }
                    </p>
                )}
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Holiday Type
                </label>

                <select
                    value={
                        data.holiday_type
                    }
                    onChange={(event) =>
                        setData(
                            "holiday_type",
                            event.target.value
                        )
                    }
                    className={inputClass}
                >
                    <option value="public">
                        Public
                    </option>

                    <option value="company">
                        Company
                    </option>

                    <option value="optional">
                        Optional
                    </option>
                </select>
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Status
                </label>

                <select
                    value={data.status}
                    onChange={(event) =>
                        setData(
                            "status",
                            event.target.value
                        )
                    }
                    className={inputClass}
                >
                    <option value="active">
                        Active
                    </option>

                    <option value="inactive">
                        Inactive
                    </option>
                </select>
            </div>

            <label className="flex items-center gap-3">
                <input
                    type="checkbox"
                    checked={data.is_paid}
                    onChange={(event) =>
                        setData(
                            "is_paid",
                            event.target.checked
                        )
                    }
                />

                <span className="text-sm font-medium">
                    Paid Holiday
                </span>
            </label>

            <div className="md:col-span-2">
                <label className="mb-1.5 block text-sm font-medium">
                    Notes
                </label>

                <textarea
                    rows={4}
                    value={data.notes}
                    onChange={(event) =>
                        setData(
                            "notes",
                            event.target.value
                        )
                    }
                    className="w-full rounded-md border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                />
            </div>
        </div>
    );
}