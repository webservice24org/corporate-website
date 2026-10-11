export type LeaveTypeFormData = {
    company_id: string;
    name: string;
    code: string;
    annual_allowance: string;
    is_paid: boolean;
    allow_half_day: boolean;
    carry_forward: boolean;
    max_carry_forward: string;
    requires_attachment: boolean;
    exclude_weekly_off: boolean;
    exclude_holidays: boolean;
    status: string;
    description: string;
};

type Company = {
    id: number;
    name: string;
};

type Props = {
    data: LeaveTypeFormData;

    setData: <K extends keyof LeaveTypeFormData>(
        key: K,
        value: LeaveTypeFormData[K]
    ) => void;

    errors: Partial<
        Record<
            keyof LeaveTypeFormData,
            string
        >
    >;

    companies: Company[];
};

export default function LeaveTypeForm({
    data,
    setData,
    errors,
    companies,
}: Props) {
    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-1 focus:ring-slate-300 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600";

    const checkboxClass =
        "h-4 w-4 rounded border-slate-300";

    return (
        <div className="grid gap-6 md:grid-cols-2">
            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Company *
                </label>

                <select
                    value={data.company_id}
                    onChange={(event) =>
                        setData(
                            "company_id",
                            event.target.value
                        )
                    }
                    className={inputClass}
                >
                    <option value="">
                        Select company
                    </option>

                    {companies.map(
                        (company) => (
                            <option
                                key={company.id}
                                value={company.id}
                            >
                                {company.name}
                            </option>
                        )
                    )}
                </select>

                {errors.company_id && (
                    <p className="mt-1 text-xs text-red-600">
                        {errors.company_id}
                    </p>
                )}
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Leave Name *
                </label>

                <input
                    type="text"
                    value={data.name}
                    onChange={(event) =>
                        setData(
                            "name",
                            event.target.value
                        )
                    }
                    placeholder="Casual Leave"
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
                    Code *
                </label>

                <input
                    type="text"
                    value={data.code}
                    onChange={(event) =>
                        setData(
                            "code",
                            event.target.value.toUpperCase()
                        )
                    }
                    placeholder="CL"
                    className={inputClass}
                />

                {errors.code && (
                    <p className="mt-1 text-xs text-red-600">
                        {errors.code}
                    </p>
                )}
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Annual Allowance *
                </label>

                <input
                    type="number"
                    min="0"
                    step="0.5"
                    value={data.annual_allowance}
                    onChange={(event) =>
                        setData(
                            "annual_allowance",
                            event.target.value
                        )
                    }
                    className={inputClass}
                />

                <p className="mt-1 text-xs text-slate-500">
                    Number of leave days available
                    each year.
                </p>

                {errors.annual_allowance && (
                    <p className="mt-1 text-xs text-red-600">
                        {errors.annual_allowance}
                    </p>
                )}
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Maximum Carry Forward
                </label>

                <input
                    type="number"
                    min="0"
                    step="0.5"
                    value={data.max_carry_forward}
                    disabled={!data.carry_forward}
                    onChange={(event) =>
                        setData(
                            "max_carry_forward",
                            event.target.value
                        )
                    }
                    className={`${inputClass} disabled:cursor-not-allowed disabled:opacity-50`}
                />

                {errors.max_carry_forward && (
                    <p className="mt-1 text-xs text-red-600">
                        {errors.max_carry_forward}
                    </p>
                )}
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Status *
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

                {errors.status && (
                    <p className="mt-1 text-xs text-red-600">
                        {errors.status}
                    </p>
                )}
            </div>

            <div className="space-y-4 rounded-lg border border-slate-200 p-4 dark:border-slate-800 md:col-span-2">
                <h3 className="font-medium">
                    Leave Rules
                </h3>

                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        checked={data.is_paid}
                        onChange={(event) =>
                            setData(
                                "is_paid",
                                event.target.checked
                            )
                        }
                        className={checkboxClass}
                    />

                    <div>
                        <div className="text-sm font-medium">
                            Paid Leave
                        </div>

                        <div className="text-xs text-slate-500">
                            Employee receives salary
                            during this leave.
                        </div>
                    </div>
                </label>

                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        checked={data.allow_half_day}
                        onChange={(event) =>
                            setData(
                                "allow_half_day",
                                event.target.checked
                            )
                        }
                        className={checkboxClass}
                    />

                    <div>
                        <div className="text-sm font-medium">
                            Allow Half Day
                        </div>

                        <div className="text-xs text-slate-500">
                            Allow employees to request
                            0.5 day leave.
                        </div>
                    </div>
                </label>

                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        checked={data.carry_forward}
                        onChange={(event) => {
                            setData(
                                "carry_forward",
                                event.target.checked
                            );

                            if (
                                !event.target.checked
                            ) {
                                setData(
                                    "max_carry_forward",
                                    "0"
                                );
                            }
                        }}
                        className={checkboxClass}
                    />

                    <div>
                        <div className="text-sm font-medium">
                            Carry Forward
                        </div>

                        <div className="text-xs text-slate-500">
                            Allow unused leave to move
                            into the next year.
                        </div>
                    </div>
                </label>

                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        checked={
                            data.requires_attachment
                        }
                        onChange={(event) =>
                            setData(
                                "requires_attachment",
                                event.target.checked
                            )
                        }
                        className={checkboxClass}
                    />

                    <div>
                        <div className="text-sm font-medium">
                            Require Attachment
                        </div>

                        <div className="text-xs text-slate-500">
                            Example: medical certificate
                            for sick leave.
                        </div>
                    </div>
                </label>

                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        checked={
                            data.exclude_weekly_off
                        }
                        onChange={(event) =>
                            setData(
                                "exclude_weekly_off",
                                event.target.checked
                            )
                        }
                        className={checkboxClass}
                    />

                    <div>
                        <div className="text-sm font-medium">
                            Exclude Weekly Off
                        </div>

                        <div className="text-xs text-slate-500">
                            Weekly off-days inside the
                            leave period will not consume
                            leave balance.
                        </div>
                    </div>
                </label>

                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        checked={
                            data.exclude_holidays
                        }
                        onChange={(event) =>
                            setData(
                                "exclude_holidays",
                                event.target.checked
                            )
                        }
                        className={checkboxClass}
                    />

                    <div>
                        <div className="text-sm font-medium">
                            Exclude Holidays
                        </div>

                        <div className="text-xs text-slate-500">
                            Company or branch holidays
                            will not consume leave
                            balance.
                        </div>
                    </div>
                </label>
            </div>

            <div className="md:col-span-2">
                <label className="mb-1.5 block text-sm font-medium">
                    Description
                </label>

                <textarea
                    rows={5}
                    value={data.description}
                    onChange={(event) =>
                        setData(
                            "description",
                            event.target.value
                        )
                    }
                    placeholder="Optional notes about this leave type..."
                    className="w-full rounded-md border border-slate-200 bg-white p-3 text-sm outline-none transition focus:border-slate-400 focus:ring-1 focus:ring-slate-300 dark:border-slate-800 dark:bg-slate-950"
                />

                {errors.description && (
                    <p className="mt-1 text-xs text-red-600">
                        {errors.description}
                    </p>
                )}
            </div>
        </div>
    );
}