import type {
    FormEvent,
    ReactNode,
} from "react";

import type {
    SetDataAction,
} from "@inertiajs/react";

export type CompanyOption = {
    id: number;
    name: string;
};

export type DivisionOption = {
    id: number;
    name: string;
    name_bn?: string | null;
};

export type DistrictOption = {
    id: number;
    division_id: number;
    name: string;
    name_bn?: string | null;
};

export type UpazilaOption = {
    id: number;
    district_id: number;
    name: string;
    name_bn?: string | null;
};

export type WarehouseTypeOption = {
    value: string;
    label: string;
};

export type WarehouseFormData = {
    company_id: number | "";
    division_id: number | "";
    district_id: number | "";
    upazila_id: number | "";

    code: string;
    name: string;
    type: string;

    contact_person: string;
    phone: string;
    email: string;

    address: string;
    postal_code: string;

    is_active: boolean;
    notes: string;
};

type FormErrors = Partial<
    Record<keyof WarehouseFormData, string>
>;

type Props = {
    data: WarehouseFormData;

    setData: SetDataAction<WarehouseFormData>;

    errors: FormErrors;

    processing: boolean;

    companies: CompanyOption[];
    divisions: DivisionOption[];
    districts: DistrictOption[];
    upazilas: UpazilaOption[];

    warehouseTypes: WarehouseTypeOption[];

    submitLabel: string;

    onSubmit: (
        event: FormEvent<HTMLFormElement>
    ) => void;
};

export default function Form({
    data,
    setData,
    errors,
    processing,
    companies,
    divisions,
    districts,
    upazilas,
    warehouseTypes,
    submitLabel,
    onSubmit,
}: Props) {
    const filteredDistricts =
        data.division_id === ""
            ? []
            : districts.filter(
                  (district) =>
                      district.division_id ===
                      Number(data.division_id)
              );

    const filteredUpazilas =
        data.district_id === ""
            ? []
            : upazilas.filter(
                  (upazila) =>
                      upazila.district_id ===
                      Number(data.district_id)
              );

    const handleDivisionChange = (
        value: string
    ) => {
        const divisionId =
            value === ""
                ? ""
                : Number(value);

        setData(
            "division_id",
            divisionId
        );

        setData(
            "district_id",
            ""
        );

        setData(
            "upazila_id",
            ""
        );
    };

    const handleDistrictChange = (
        value: string
    ) => {
        const districtId =
            value === ""
                ? ""
                : Number(value);

        setData(
            "district_id",
            districtId
        );

        setData(
            "upazila_id",
            ""
        );
    };

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <div className="grid min-w-0 gap-6 2xl:grid-cols-[minmax(0,1fr)_360px]">
                {/* Main column */}
                <div className="min-w-0 space-y-6">
                    <FormCard
                        title="Warehouse Information"
                        description="Basic identification and operational classification."
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <Field
                                label="Warehouse Name"
                                required
                                error={errors.name}
                            >
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "name",
                                            event
                                                .target
                                                .value
                                        )
                                    }
                                    placeholder="e.g. Dhaka Central Warehouse"
                                    className={inputClass}
                                />
                            </Field>

                            <Field
                                label="Warehouse Code"
                                required
                                error={errors.code}
                                hint="Code must be unique within the selected company."
                            >
                                <input
                                    type="text"
                                    value={data.code}
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "code",
                                            event
                                                .target
                                                .value
                                                .toUpperCase()
                                        )
                                    }
                                    placeholder="e.g. WH-DHK-001"
                                    className={inputClass}
                                />
                            </Field>

                            <Field
                                label="Warehouse Type"
                                required
                                error={errors.type}
                            >
                                <select
                                    value={data.type}
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "type",
                                            event
                                                .target
                                                .value
                                        )
                                    }
                                    className={inputClass}
                                >
                                    <option value="">
                                        Select warehouse type
                                    </option>

                                    {warehouseTypes.map(
                                        (type) => (
                                            <option
                                                key={
                                                    type.value
                                                }
                                                value={
                                                    type.value
                                                }
                                            >
                                                {
                                                    type.label
                                                }
                                            </option>
                                        )
                                    )}
                                </select>
                            </Field>
                        </div>
                    </FormCard>

                    <FormCard
                        title="Contact Information"
                        description="Primary operational contact for this warehouse."
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <Field
                                label="Contact Person"
                                error={
                                    errors.contact_person
                                }
                            >
                                <input
                                    type="text"
                                    value={
                                        data.contact_person
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "contact_person",
                                            event
                                                .target
                                                .value
                                        )
                                    }
                                    placeholder="Contact person name"
                                    className={inputClass}
                                />
                            </Field>

                            <Field
                                label="Phone"
                                error={errors.phone}
                            >
                                <input
                                    type="text"
                                    value={data.phone}
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "phone",
                                            event
                                                .target
                                                .value
                                        )
                                    }
                                    placeholder="Phone number"
                                    className={inputClass}
                                />
                            </Field>

                            <Field
                                label="Email"
                                error={errors.email}
                            >
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "email",
                                            event
                                                .target
                                                .value
                                        )
                                    }
                                    placeholder="warehouse@example.com"
                                    className={inputClass}
                                />
                            </Field>
                        </div>
                    </FormCard>

                    <FormCard
                        title="Warehouse Location"
                        description="Geographic and physical location of the warehouse."
                    >
                        <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                            <Field
                                label="Division"
                                error={
                                    errors.division_id
                                }
                            >
                                <select
                                    value={
                                        data.division_id
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        handleDivisionChange(
                                            event
                                                .target
                                                .value
                                        )
                                    }
                                    className={inputClass}
                                >
                                    <option value="">
                                        Select Division
                                    </option>

                                    {divisions.map(
                                        (division) => (
                                            <option
                                                key={
                                                    division.id
                                                }
                                                value={
                                                    division.id
                                                }
                                            >
                                                {
                                                    division.name
                                                }
                                                {division.name_bn
                                                    ? ` — ${division.name_bn}`
                                                    : ""}
                                            </option>
                                        )
                                    )}
                                </select>
                            </Field>

                            <Field
                                label="District"
                                error={
                                    errors.district_id
                                }
                            >
                                <select
                                    value={
                                        data.district_id
                                    }
                                    disabled={
                                        data.division_id ===
                                        ""
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        handleDistrictChange(
                                            event
                                                .target
                                                .value
                                        )
                                    }
                                    className={`${inputClass} disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 dark:disabled:bg-slate-900`}
                                >
                                    <option value="">
                                        Select District
                                    </option>

                                    {filteredDistricts.map(
                                        (district) => (
                                            <option
                                                key={
                                                    district.id
                                                }
                                                value={
                                                    district.id
                                                }
                                            >
                                                {
                                                    district.name
                                                }
                                                {district.name_bn
                                                    ? ` — ${district.name_bn}`
                                                    : ""}
                                            </option>
                                        )
                                    )}
                                </select>
                            </Field>

                            <Field
                                label="Upazila"
                                error={
                                    errors.upazila_id
                                }
                            >
                                <select
                                    value={
                                        data.upazila_id
                                    }
                                    disabled={
                                        data.district_id ===
                                        ""
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "upazila_id",
                                            event
                                                .target
                                                .value ===
                                                ""
                                                ? ""
                                                : Number(
                                                      event
                                                          .target
                                                          .value
                                                  )
                                        )
                                    }
                                    className={`${inputClass} disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 dark:disabled:bg-slate-900`}
                                >
                                    <option value="">
                                        Select Upazila
                                    </option>

                                    {filteredUpazilas.map(
                                        (upazila) => (
                                            <option
                                                key={
                                                    upazila.id
                                                }
                                                value={
                                                    upazila.id
                                                }
                                            >
                                                {
                                                    upazila.name
                                                }
                                                {upazila.name_bn
                                                    ? ` — ${upazila.name_bn}`
                                                    : ""}
                                            </option>
                                        )
                                    )}
                                </select>
                            </Field>
                        </div>

                        <div className="mt-5 grid gap-5 md:grid-cols-[minmax(0,1fr)_200px]">
                            <Field
                                label="Address"
                                error={
                                    errors.address
                                }
                            >
                                <textarea
                                    value={
                                        data.address
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "address",
                                            event
                                                .target
                                                .value
                                        )
                                    }
                                    rows={4}
                                    placeholder="Full warehouse address"
                                    className={textareaClass}
                                />
                            </Field>

                            <Field
                                label="Postal Code"
                                error={
                                    errors.postal_code
                                }
                            >
                                <input
                                    type="text"
                                    value={
                                        data.postal_code
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setData(
                                            "postal_code",
                                            event
                                                .target
                                                .value
                                        )
                                    }
                                    placeholder="Postal code"
                                    className={inputClass}
                                />
                            </Field>
                        </div>
                    </FormCard>

                    <FormCard
                        title="Internal Notes"
                        description="Internal operational information. This is not public-facing."
                    >
                        <Field
                            label="Notes"
                            error={errors.notes}
                        >
                            <textarea
                                value={data.notes}
                                onChange={(
                                    event
                                ) =>
                                    setData(
                                        "notes",
                                        event
                                            .target
                                            .value
                                    )
                                }
                                rows={6}
                                placeholder="Internal notes about this warehouse..."
                                className={textareaClass}
                            />
                        </Field>
                    </FormCard>
                </div>

                {/* Sidebar */}
                <aside className="min-w-0 space-y-6">
                    <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950 2xl:sticky 2xl:top-6">
                        <h2 className="font-semibold">
                            Warehouse Settings
                        </h2>

                        <p className="mt-1 text-sm text-slate-500">
                            Company ownership and operational
                            status.
                        </p>

                        <div className="mt-5 space-y-5">
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
                                        event
                                    ) =>
                                        setData(
                                            "company_id",
                                            event
                                                .target
                                                .value ===
                                                ""
                                                ? ""
                                                : Number(
                                                      event
                                                          .target
                                                          .value
                                                  )
                                        )
                                    }
                                    className={inputClass}
                                >
                                    <option value="">
                                        Select Company
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

                            <div className="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                                <label className="flex cursor-pointer items-start gap-3">
                                    <input
                                        type="checkbox"
                                        checked={
                                            data.is_active
                                        }
                                        onChange={(
                                            event
                                        ) =>
                                            setData(
                                                "is_active",
                                                event
                                                    .target
                                                    .checked
                                            )
                                        }
                                        className="mt-0.5 h-4 w-4 rounded border-slate-300"
                                    />

                                    <span>
                                        <span className="block text-sm font-medium">
                                            Active Warehouse
                                        </span>

                                        <span className="mt-1 block text-xs leading-5 text-slate-500">
                                            Inactive warehouses
                                            remain available for
                                            historical records but
                                            should not be used for
                                            new inventory
                                            transactions.
                                        </span>
                                    </span>
                                </label>

                                {errors.is_active && (
                                    <p className="mt-2 text-xs text-red-500">
                                        {
                                            errors.is_active
                                        }
                                    </p>
                                )}
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex h-11 w-full items-center justify-center rounded-md bg-slate-900 px-4 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                            >
                                {processing
                                    ? "Saving..."
                                    : submitLabel}
                            </button>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    );
}

function FormCard({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="min-w-0 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
            <div className="mb-5">
                <h2 className="font-semibold">
                    {title}
                </h2>

                {description && (
                    <p className="mt-1 text-sm text-slate-500">
                        {description}
                    </p>
                )}
            </div>

            {children}
        </section>
    );
}

function Field({
    label,
    required = false,
    error,
    hint,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="min-w-0">
            <label className="mb-1.5 block text-sm font-medium">
                {label}

                {required && (
                    <span className="ml-1 text-red-500">
                        *
                    </span>
                )}
            </label>

            {children}

            {hint && !error && (
                <p className="mt-1.5 text-xs leading-5 text-slate-500">
                    {hint}
                </p>
            )}

            {error && (
                <p className="mt-1.5 text-xs text-red-500">
                    {error}
                </p>
            )}
        </div>
    );
}

const inputClass =
    "h-10 w-full min-w-0 rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-100 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-900";

const textareaClass =
    "w-full min-w-0 resize-y rounded-md border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-100 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-900";