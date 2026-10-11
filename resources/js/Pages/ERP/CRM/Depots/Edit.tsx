import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Building2,
    FileText,
    ImageIcon,
    Landmark,
    MapPin,
    Save,
    Trash2,
    Upload,
    UserRound,
} from "lucide-react";

import {
    useEffect,
    useMemo,
    useState,
} from "react";

import type {
    ChangeEvent,
    FormEvent,
    ReactNode,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

type Option = {
    id: number;
    name: string;
};

type DistrictOption = Option & {
    division_id: number;
};

type ManagerOption = {
    id: number;
    name: string;
    email?: string | null;
};

type Depot = {
    id: number;
    company_id: number;
    division_id: number;
    district_id: number;

    depot_code?: string | null;
    name: string;

    proprietor_name: string;
    proprietor_photo?: string | null;

    phone: string;
    alternate_phone?: string | null;
    email?: string | null;

    trade_license_number?: string | null;
    nid_number?: string | null;
    tin_number?: string | null;
    vat_number?: string | null;

    address: string;
    postal_code?: string | null;

    credit_limit?: string | number | null;
    payment_terms_days?: number | null;

    manager_user_id?: number | null;

    is_active: boolean;
    notes?: string | null;

    division?: {
        id: number;
        name: string;
    } | null;

    district?: {
        id: number;
        division_id: number;
        name: string;
    } | null;
};

type FormData = {
    company_id: number | "";
    division_id: number | "";
    district_id: number | "";

    name: string;

    proprietor_name: string;
    proprietor_photo: File | null;

    phone: string;
    alternate_phone: string;
    email: string;

    trade_license_number: string;
    nid_number: string;
    tin_number: string;
    vat_number: string;

    address: string;
    postal_code: string;

    credit_limit: number | "";
    payment_terms_days: number | "";

    manager_user_id: number | "";

    is_active: boolean;
    notes: string;
};

type Props = {
    depot: Depot;
    companies: Option[];
    divisions: Option[];
    districts: DistrictOption[];
    managers: ManagerOption[];
};

const inputClass =
    "h-10 w-full rounded-md border border-neutral-300 bg-white px-3 text-sm outline-none focus:border-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white";

const textareaClass =
    "min-h-28 w-full rounded-md border border-neutral-300 bg-white p-3 text-sm outline-none focus:border-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white";

const labelClass =
    "mb-1.5 block text-sm font-medium";

function ErrorText({
    message,
}: {
    message?: string;
}) {
    return message ? (
        <p className="mt-1 text-xs text-red-600">
            {message}
        </p>
    ) : null;
}

export default function Edit({
    depot,
    companies,
    divisions,
    districts,
    managers,
}: Props) {
    const form =
        useForm<FormData>({
            company_id:
                depot.company_id,

            division_id:
                depot.division_id,

            district_id:
                depot.district_id,

            name:
                depot.name,

            proprietor_name:
                depot.proprietor_name,

            proprietor_photo:
                null,

            phone:
                depot.phone,

            alternate_phone:
                depot.alternate_phone ??
                "",

            email:
                depot.email ??
                "",

            trade_license_number:
                depot.trade_license_number ??
                "",

            nid_number:
                depot.nid_number ??
                "",

            tin_number:
                depot.tin_number ??
                "",

            vat_number:
                depot.vat_number ??
                "",

            address:
                depot.address,

            postal_code:
                depot.postal_code ??
                "",

            credit_limit:
                Number(
                    depot.credit_limit ??
                        0
                ),

            payment_terms_days:
                depot.payment_terms_days ??
                0,

            manager_user_id:
                depot.manager_user_id ??
                "",

            is_active:
                depot.is_active,

            notes:
                depot.notes ??
                "",
        });

    const availableDistricts =
        useMemo(() => {
            if (
                form.data.division_id ===
                ""
            ) {
                return [];
            }

            return districts.filter(
                (district) =>
                    district.division_id ===
                    form.data.division_id
            );
        }, [
            districts,
            form.data.division_id,
        ]);

    const changeDivision = (
        value: string
    ) => {
        form.setData(
            "division_id",
            value
                ? Number(value)
                : ""
        );

        form.setData(
            "district_id",
            ""
        );
    };

    const submit = (
        event: FormEvent
    ) => {
        event.preventDefault();

        form.transform(
            (data) => ({
                ...data,
                _method: "put",
            })
        );

        form.post(
            `/erp/crm/depots/${depot.id}`,
            {
                preserveScroll: true,
                forceFormData: true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head
                title={`Edit Depot - ${depot.name}`}
            />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <Link
                            href={`/erp/crm/depots/${depot.id}`}
                            className="mb-3 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-950 dark:hover:text-white"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Back to Depot
                        </Link>

                        <h1 className="text-2xl font-bold">
                            Edit Approved Depot
                        </h1>

                        <p className="mt-1 text-sm text-neutral-500">
                            {depot.name}
                            {depot.depot_code
                                ? ` · ${depot.depot_code}`
                                : ""}
                        </p>
                    </div>
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-6"
                >
                    <Card
                        title="Organization & Distribution"
                        icon={Landmark}
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <div>
                                <label className={labelClass}>
                                    Company *
                                </label>

                                <select
                                    value={form.data.company_id}
                                    onChange={(event) =>
                                        form.setData(
                                            "company_id",
                                            event.target.value
                                                ? Number(
                                                      event.target.value
                                                  )
                                                : ""
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
                                                key={company.id}
                                                value={company.id}
                                            >
                                                {company.name}
                                            </option>
                                        )
                                    )}
                                </select>

                                <ErrorText
                                    message={
                                        form.errors.company_id
                                    }
                                />
                            </div>

                            <div>
                                <label className={labelClass}>
                                    Division *
                                </label>

                                <select
                                    value={form.data.division_id}
                                    onChange={(event) =>
                                        changeDivision(
                                            event.target.value
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
                                                key={division.id}
                                                value={division.id}
                                            >
                                                {division.name}
                                            </option>
                                        )
                                    )}
                                </select>
                            </div>

                            <div>
                                <label className={labelClass}>
                                    District *
                                </label>

                                <select
                                    value={form.data.district_id}
                                    disabled={
                                        !form.data.division_id
                                    }
                                    onChange={(event) =>
                                        form.setData(
                                            "district_id",
                                            event.target.value
                                                ? Number(
                                                      event.target.value
                                                  )
                                                : ""
                                        )
                                    }
                                    className={inputClass}
                                >
                                    <option value="">
                                        Select District
                                    </option>

                                    {availableDistricts.map(
                                        (district) => (
                                            <option
                                                key={district.id}
                                                value={district.id}
                                            >
                                                {district.name}
                                            </option>
                                        )
                                    )}
                                </select>

                                <ErrorText
                                    message={
                                        form.errors.district_id
                                    }
                                />
                            </div>

                            <Field
                                label="Depot / Business Name *"
                                value={form.data.name}
                                onChange={(value) =>
                                    form.setData(
                                        "name",
                                        value
                                    )
                                }
                                error={
                                    form.errors.name
                                }
                            />
                        </div>
                    </Card>

                    <Card
                        title="Proprietor & Contact"
                        icon={Building2}
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <Field
                                label="Proprietor Name *"
                                value={
                                    form.data.proprietor_name
                                }
                                onChange={(value) =>
                                    form.setData(
                                        "proprietor_name",
                                        value
                                    )
                                }
                                error={
                                    form.errors.proprietor_name
                                }
                            />

                            <div className="md:col-span-2">
                                <ProprietorPhotoField
                                    file={
                                        form.data.proprietor_photo
                                    }
                                    existingPhoto={
                                        depot.proprietor_photo
                                            ? `/erp/crm/depots/${depot.id}/proprietor-photo`
                                            : null
                                    }
                                    onChange={(event) => {
                                        form.setData(
                                            "proprietor_photo",
                                            event.target.files?.[0] ??
                                                null
                                        );

                                        event.target.value =
                                            "";
                                    }}
                                    onRemove={() =>
                                        form.setData(
                                            "proprietor_photo",
                                            null
                                        )
                                    }
                                    error={
                                        form.errors.proprietor_photo
                                    }
                                />
                            </div>

                            <Field
                                label="Phone *"
                                value={form.data.phone}
                                onChange={(value) =>
                                    form.setData(
                                        "phone",
                                        value
                                    )
                                }
                                error={
                                    form.errors.phone
                                }
                            />

                            <Field
                                label="Alternate Phone"
                                value={
                                    form.data.alternate_phone
                                }
                                onChange={(value) =>
                                    form.setData(
                                        "alternate_phone",
                                        value
                                    )
                                }
                                error={
                                    form.errors.alternate_phone
                                }
                            />

                            <Field
                                label="Email"
                                type="email"
                                value={form.data.email}
                                onChange={(value) =>
                                    form.setData(
                                        "email",
                                        value
                                    )
                                }
                                error={
                                    form.errors.email
                                }
                            />
                        </div>
                    </Card>

                    <Card
                        title="Legal Information"
                        icon={FileText}
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <Field
                                label="Trade License Number"
                                value={
                                    form.data.trade_license_number
                                }
                                onChange={(value) =>
                                    form.setData(
                                        "trade_license_number",
                                        value
                                    )
                                }
                                error={
                                    form.errors.trade_license_number
                                }
                            />

                            <Field
                                label="NID Number"
                                value={
                                    form.data.nid_number
                                }
                                onChange={(value) =>
                                    form.setData(
                                        "nid_number",
                                        value
                                    )
                                }
                                error={
                                    form.errors.nid_number
                                }
                            />

                            <Field
                                label="TIN Number"
                                value={
                                    form.data.tin_number
                                }
                                onChange={(value) =>
                                    form.setData(
                                        "tin_number",
                                        value
                                    )
                                }
                                error={
                                    form.errors.tin_number
                                }
                            />

                            <Field
                                label="VAT / BIN Number"
                                value={
                                    form.data.vat_number
                                }
                                onChange={(value) =>
                                    form.setData(
                                        "vat_number",
                                        value
                                    )
                                }
                                error={
                                    form.errors.vat_number
                                }
                            />
                        </div>
                    </Card>

                    <Card
                        title="Business Address"
                        icon={MapPin}
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <div className="md:col-span-2">
                                <label className={labelClass}>
                                    Address *
                                </label>

                                <textarea
                                    value={
                                        form.data.address
                                    }
                                    onChange={(event) =>
                                        form.setData(
                                            "address",
                                            event.target.value
                                        )
                                    }
                                    className={textareaClass}
                                />

                                <ErrorText
                                    message={
                                        form.errors.address
                                    }
                                />
                            </div>

                            <Field
                                label="Postal Code"
                                value={
                                    form.data.postal_code
                                }
                                onChange={(value) =>
                                    form.setData(
                                        "postal_code",
                                        value
                                    )
                                }
                                error={
                                    form.errors.postal_code
                                }
                            />
                        </div>
                    </Card>

                    <Card
                        title="Commercial & Administration"
                        icon={UserRound}
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <NumberField
                                label="Credit Limit"
                                value={
                                    form.data.credit_limit
                                }
                                step="0.01"
                                onChange={(value) =>
                                    form.setData(
                                        "credit_limit",
                                        value
                                    )
                                }
                                error={
                                    form.errors.credit_limit
                                }
                            />

                            <NumberField
                                label="Payment Terms (Days)"
                                value={
                                    form.data.payment_terms_days
                                }
                                onChange={(value) =>
                                    form.setData(
                                        "payment_terms_days",
                                        value
                                    )
                                }
                                error={
                                    form.errors.payment_terms_days
                                }
                            />

                            <div>
                                <label className={labelClass}>
                                    Manager / Responsible User
                                </label>

                                <select
                                    value={
                                        form.data.manager_user_id
                                    }
                                    onChange={(event) =>
                                        form.setData(
                                            "manager_user_id",
                                            event.target.value
                                                ? Number(
                                                      event.target.value
                                                  )
                                                : ""
                                        )
                                    }
                                    className={inputClass}
                                >
                                    <option value="">
                                        No manager
                                    </option>

                                    {managers.map(
                                        (manager) => (
                                            <option
                                                key={manager.id}
                                                value={manager.id}
                                            >
                                                {manager.name}
                                                {manager.email
                                                    ? ` - ${manager.email}`
                                                    : ""}
                                            </option>
                                        )
                                    )}
                                </select>

                                <ErrorText
                                    message={
                                        form.errors.manager_user_id
                                    }
                                />
                            </div>

                            <div>
                                <label className={labelClass}>
                                    Status
                                </label>

                                <select
                                    value={
                                        form.data.is_active
                                            ? "1"
                                            : "0"
                                    }
                                    onChange={(event) =>
                                        form.setData(
                                            "is_active",
                                            event.target.value ===
                                                "1"
                                        )
                                    }
                                    className={inputClass}
                                >
                                    <option value="1">
                                        Active
                                    </option>

                                    <option value="0">
                                        Inactive
                                    </option>
                                </select>

                                <ErrorText
                                    message={
                                        form.errors.is_active
                                    }
                                />
                            </div>

                            <div className="md:col-span-2">
                                <label className={labelClass}>
                                    Notes
                                </label>

                                <textarea
                                    value={
                                        form.data.notes
                                    }
                                    onChange={(event) =>
                                        form.setData(
                                            "notes",
                                            event.target.value
                                        )
                                    }
                                    className={textareaClass}
                                />

                                <ErrorText
                                    message={
                                        form.errors.notes
                                    }
                                />
                            </div>
                        </div>
                    </Card>

                    <div className="flex flex-wrap justify-end gap-3">
                        <Link
                            href={`/erp/crm/depots/${depot.id}`}
                            className="inline-flex h-10 items-center justify-center rounded-md border border-neutral-300 px-4 text-sm font-medium dark:border-neutral-700"
                        >
                            Cancel
                        </Link>

                        <button
                            type="submit"
                            disabled={
                                form.processing
                            }
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-neutral-950"
                        >
                            <Save className="h-4 w-4" />

                            {form.processing
                                ? "Saving..."
                                : "Save Depot Changes"}
                        </button>
                    </div>
                </form>
            </div>
        </ERPLayout>
    );
}

function Card({
    title,
    icon: Icon,
    children,
}: {
    title: string;
    icon: typeof Building2;
    children: ReactNode;
}) {
    return (
        <section className="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
            <div className="mb-5 flex items-center gap-2">
                <Icon className="h-5 w-5" />
                <h2 className="font-semibold">
                    {title}
                </h2>
            </div>

            {children}
        </section>
    );
}

function Field({
    label,
    value,
    onChange,
    type = "text",
    error,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    type?: string;
    error?: string;
}) {
    return (
        <div>
            <label className={labelClass}>
                {label}
            </label>

            <input
                type={type}
                value={value}
                onChange={(event) =>
                    onChange(
                        event.target.value
                    )
                }
                className={inputClass}
            />

            <ErrorText
                message={error}
            />
        </div>
    );
}

function NumberField({
    label,
    value,
    onChange,
    step = "1",
    error,
}: {
    label: string;
    value: number | "";
    onChange: (
        value: number | ""
    ) => void;
    step?: string;
    error?: string;
}) {
    return (
        <div>
            <label className={labelClass}>
                {label}
            </label>

            <input
                type="number"
                min="0"
                step={step}
                value={value}
                onChange={(event) =>
                    onChange(
                        event.target.value
                            ? Number(
                                  event.target.value
                              )
                            : ""
                    )
                }
                className={inputClass}
            />

            <ErrorText
                message={error}
            />
        </div>
    );
}

function ProprietorPhotoField({
    file,
    existingPhoto,
    onChange,
    onRemove,
    error,
}: {
    file: File | null;
    existingPhoto?: string | null;
    onChange: (
        event:
            ChangeEvent<HTMLInputElement>
    ) => void;
    onRemove: () => void;
    error?: string;
}) {
    const [
        previewUrl,
        setPreviewUrl,
    ] =
        useState<string | null>(
            null
        );

    useEffect(() => {
        if (!file) {
            setPreviewUrl(
                null
            );

            return;
        }

        const url =
            URL.createObjectURL(
                file
            );

        setPreviewUrl(
            url
        );

        return () => {
            URL.revokeObjectURL(
                url
            );
        };
    }, [file]);

    const displayedPhoto =
        previewUrl ??
        existingPhoto;

    return (
        <div>
            <label className={labelClass}>
                Proprietor Picture
            </label>

            <div className="rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <div className="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        {displayedPhoto ? (
                            <img
                                src={
                                    displayedPhoto
                                }
                                alt="Proprietor"
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <UserRound className="h-10 w-10 text-neutral-400" />
                        )}
                    </div>

                    <div className="min-w-0 flex-1">
                        {file ? (
                            <>
                                <p className="truncate text-sm font-medium">
                                    {file.name}
                                </p>

                                <p className="mt-1 text-xs text-neutral-500">
                                    New picture selected
                                </p>
                            </>
                        ) : existingPhoto ? (
                            <p className="text-sm text-neutral-500">
                                Existing proprietor picture
                            </p>
                        ) : (
                            <p className="text-sm text-neutral-500">
                                No proprietor picture uploaded
                            </p>
                        )}

                        <div className="mt-3 flex flex-wrap gap-2">
                            <label className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-neutral-200 bg-white px-3 text-sm font-medium transition hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800">
                                <Upload className="h-4 w-4" />

                                {displayedPhoto
                                    ? "Change Picture"
                                    : "Choose Picture"}

                                <input
                                    type="file"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                    onChange={onChange}
                                    className="hidden"
                                />
                            </label>

                            {file && (
                                <button
                                    type="button"
                                    onClick={onRemove}
                                    className="inline-flex h-9 items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 text-sm font-medium text-red-600"
                                >
                                    <Trash2 className="h-4 w-4" />
                                    Cancel New Picture
                                </button>
                            )}

                            {existingPhoto &&
                                !file && (
                                    <a
                                        href={
                                            existingPhoto
                                        }
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex h-9 items-center gap-2 rounded-md border border-neutral-200 bg-white px-3 text-sm font-medium dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <ImageIcon className="h-4 w-4" />
                                        View Full Picture
                                    </a>
                                )}
                        </div>

                        <p className="mt-2 text-xs text-neutral-500">
                            JPG, PNG or WEBP. Maximum 2 MB.
                        </p>
                    </div>
                </div>
            </div>

            <ErrorText
                message={error}
            />
        </div>
    );
}
