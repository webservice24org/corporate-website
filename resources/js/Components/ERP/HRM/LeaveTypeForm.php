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
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-slate-400 dark:border-slate-800 dark:bg-slate-950";

    return (
        <div className="grid gap-5 md:grid-cols-2">
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
                    value={data.name}
                    onChange={(event) =>
                        setData(
                            "name",
                            event.target.value
                        )
                    }
                    className={inputClass}
                />
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Code *
                </label>

                <input
                    value={data.code}
                    onChange={(event) =>
                        setData(
                            "code",
                            event.target.value.toUpperCase()
                        )
                    }
                    className={inputClass}
                />
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Annual Allowance
                </label>

                <input
                    type="number"
                    step="0.5"
                    min="0"
                    value={data.annual_allowance}
                    onChange={(event) =>
                        setData(
                            "annual_allowance",
                            event.target.value
                        )
                    }
                    className={inputClass}
                />
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium">
                    Maximum Carry Forward
                </label>

                <input
                    type="number"
                    step="0.5"
                    min="0"
                    value={data.max_carry_forward}
                    onChange={(event) =>
                        setData(
                            "max_carry_forward",
                            event.target.value
                        )
                    }
                    disabled={!data.carry_forward}
                    className={inputClass}
                />
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

            <div className="space-y-3 md:col-span-2">
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

                    <span className="text-sm">
                        Paid leave
                    </span>
                </label>

                <label className="flex items-center gap-3">
                    <input
                        type="checkbox"
                        checked={data.allow_half_day}
                        onChange={(event) =>
                            setData(
                                "allow_half_day",
                                event.target.checked
                            )
                        }
                    />

                    <span className="text-sm">
                        Allow half-day requests
                    </span>
                </label>

                <label className="flex items-center gap-3">
                    <input
                        type="checkbox"
                        checked={data.carry_forward}
                        onChange={(event) =>
                            setData(
                                "carry_forward",
                                event.target.checked
                            )
                        }
                    />

                    <span className="text-sm">
                        Carry unused balance forward
                    </span>
                </label>

                <label className="flex items-center gap-3">
                    <input
                        type="checkbox"
                        checked={data.requires_attachment}
                        onChange={(event) =>
                            setData(
                                "requires_attachment",
                                event.target.checked
                            )
                        }
                    />

                    <span className="text-sm">
                        Require attachment
                    </span>
                </label>

                <label className="flex items-center gap-3">
                    <input
                        type="checkbox"
                        checked={data.exclude_weekly_off}
                        onChange={(event) =>
                            setData(
                                "exclude_weekly_off",
                                event.target.checked
                            )
                        }
                    />

                    <span className="text-sm">
                        Exclude weekly off-days from leave calculation
                    </span>
                </label>

                <label className="flex items-center gap-3">
                    <input
                        type="checkbox"
                        checked={data.exclude_holidays}
                        onChange={(event) =>
                            setData(
                                "exclude_holidays",
                                event.target.checked
                            )
                        }
                    />

                    <span className="text-sm">
                        Exclude holidays from leave calculation
                    </span>
                </label>
            </div>

            <div className="md:col-span-2">
                <label className="mb-1.5 block text-sm font-medium">
                    Description
                </label>

                <textarea
                    rows={4}
                    value={data.description}
                    onChange={(event) =>
                        setData(
                            "description",
                            event.target.value
                        )
                    }
                    className="w-full rounded-md border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                />
            </div>
        </div>
    );
}