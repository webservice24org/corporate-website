import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Building2,
    Camera,
    CreditCard,
    FileText,
    Save,
    Store,
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

type Company = {
    id: number;
    name: string;
};

type Division = {
    id: number;
    name: string;
    name_bn?: string | null;
};

type District = {
    id: number;
    division_id: number;
    name: string;
    name_bn?: string | null;
    division?: Division | null;
};

type Depot = {
    id: number;
    company_id: number;
    district_id: number;
    name: string;
    depot_code?: string | null;
    is_active: boolean;
    company?: Company | null;
    district?: District | null;
};

type Upazila = {
    id: number;
    district_id: number;
    name: string;
    name_bn?: string | null;
    code?: string | null;
    is_active: boolean;
};

type Manager = {
    id: number;
    name: string;
    email?: string | null;
};

type Dealer = {
    id: number;
    company_id: number;
    depot_id: number;
    upazila_id: number;
    dealer_code?: string | null;
    business_name: string;
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
};

type Props = {
    dealer: Dealer;
    depots: Depot[];
    upazilas: Upazila[];
    managers: Manager[];
};

type DealerFormData = {
    depot_id: number | "";
    upazila_id: number | "";
    business_name: string;
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
    credit_limit: number | string;
    payment_terms_days: number | string;
    manager_user_id: number | "";
    is_active: boolean;
    notes: string;
};

export default function Edit({
    dealer,
    depots,
    upazilas,
    managers,
}: Props) {
    const form =
        useForm<DealerFormData>({
            depot_id: dealer.depot_id,
            upazila_id: dealer.upazila_id,
            business_name:
                dealer.business_name ?? "",
            proprietor_name:
                dealer.proprietor_name ?? "",
            proprietor_photo: null,
            phone: dealer.phone ?? "",
            alternate_phone:
                dealer.alternate_phone ?? "",
            email: dealer.email ?? "",
            trade_license_number:
                dealer.trade_license_number ?? "",
            nid_number:
                dealer.nid_number ?? "",
            tin_number:
                dealer.tin_number ?? "",
            vat_number:
                dealer.vat_number ?? "",
            address:
                dealer.address ?? "",
            postal_code:
                dealer.postal_code ?? "",
            credit_limit:
                dealer.credit_limit ?? 0,
            payment_terms_days:
                dealer.payment_terms_days ?? 0,
            manager_user_id:
                dealer.manager_user_id ?? "",
            is_active:
                Boolean(dealer.is_active),
            notes:
                dealer.notes ?? "",
        });

    const selectedDepot =
        useMemo(
            () =>
                depots.find(
                    (depot) =>
                        depot.id ===
                        Number(
                            form.data.depot_id
                        )
                ) ?? null,
            [
                depots,
                form.data.depot_id,
            ]
        );

    const availableUpazilas =
        useMemo(() => {
            if (!selectedDepot) {
                return [];
            }

            return upazilas.filter(
                (upazila) =>
                    upazila.district_id ===
                    selectedDepot.district_id
            );
        }, [
            selectedDepot,
            upazilas,
        ]);

    const changeDepot = (
        value: string
    ) => {
        const depotId =
            value
                ? Number(value)
                : "";

        form.setData(
            "depot_id",
            depotId
        );

        const depot =
            depots.find(
                (item) =>
                    item.id ===
                    Number(depotId)
            );

        const currentUpazila =
            upazilas.find(
                (item) =>
                    item.id ===
                    Number(
                        form.data.upazila_id
                    )
            );

        if (
            !depot ||
            !currentUpazila ||
            currentUpazila.district_id !==
                depot.district_id
        ) {
            form.setData(
                "upazila_id",
                ""
            );
        }
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
            `/erp/crm/dealers/${dealer.id}`,
            {
                preserveScroll: true,
                forceFormData: true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head
                title={`Edit Dealer - ${dealer.business_name}`}
            />

            <div className="space-y-6">
                <div>
                    <Link
                        href={`/erp/crm/dealers/${dealer.id}`}
                        className="mb-3 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-950 dark:hover:text-white"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to Dealer
                    </Link>

                    <h1 className="text-2xl font-semibold">
                        Edit Dealer
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        {dealer.business_name}
                        {dealer.dealer_code
                            ? ` · ${dealer.dealer_code}`
                            : ""}
                    </p>
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-6"
                >
                    <Card
                        title="Distribution Assignment"
                        icon={Store}
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <FieldShell
                                label="Depot *"
                                error={
                                    form.errors.depot_id
                                }
                            >
                                <select
                                    value={
                                        form.data.depot_id
                                    }
                                    onChange={(event) =>
                                        changeDepot(
                                            event.target.value
                                        )
                                    }
                                    className={inputClass}
                                >
                                    <option value="">
                                        Select Depot
                                    </option>

                                    {depots.map(
                                        (depot) => (
                                            <option
                                                key={depot.id}
                                                value={depot.id}
                                            >
                                                {depot.name}
                                                {depot.depot_code
                                                    ? ` (${depot.depot_code})`
                                                    : ""}
                                            </option>
                                        )
                                    )}
                                </select>
                            </FieldShell>

                            <FieldShell
                                label="Upazila *"
                                error={
                                    form.errors.upazila_id
                                }
                            >
                                <select
                                    value={
                                        form.data.upazila_id
                                    }
                                    onChange={(event) =>
                                        form.setData(
                                            "upazila_id",
                                            event.target.value
                                                ? Number(
                                                      event.target.value
                                                  )
                                                : ""
                                        )
                                    }
                                    disabled={
                                        !selectedDepot
                                    }
                                    className={inputClass}
                                >
                                    <option value="">
                                        Select Upazila
                                    </option>

                                    {availableUpazilas.map(
                                        (upazila) => (
                                            <option
                                                key={upazila.id}
                                                value={upazila.id}
                                            >
                                                {upazila.name}
                                                {upazila.name_bn
                                                    ? ` / ${upazila.name_bn}`
                                                    : ""}
                                            </option>
                                        )
                                    )}
                                </select>
                            </FieldShell>
                        </div>

                        {selectedDepot && (
                            <div className="mt-5 grid gap-4 rounded-lg bg-neutral-50 p-4 text-sm sm:grid-cols-3 dark:bg-neutral-900/50">
                                <MiniInfo
                                    label="Company"
                                    value={
                                        selectedDepot
                                            .company
                                            ?.name ??
                                        "—"
                                    }
                                />

                                <MiniInfo
                                    label="District"
                                    value={
                                        selectedDepot
                                            .district
                                            ?.name ??
                                        "—"
                                    }
                                />

                                <MiniInfo
                                    label="Division"
                                    value={
                                        selectedDepot
                                            .district
                                            ?.division
                                            ?.name ??
                                        "—"
                                    }
                                />
                            </div>
                        )}
                    </Card>

                    <Card
                        title="Business Information"
                        icon={Building2}
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <TextInput
                                label="Business Name *"
                                value={
                                    form.data.business_name
                                }
                                onChange={(value) =>
                                    form.setData(
                                        "business_name",
                                        value
                                    )
                                }
                                error={
                                    form.errors.business_name
                                }
                            />

                            <TextInput
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
                        </div>

                        <div className="mt-5">
                            <ProprietorPhotoField
                                dealerId={dealer.id}
                                existingPhoto={
                                    dealer.proprietor_photo
                                }
                                file={
                                    form.data.proprietor_photo
                                }
                                error={
                                    form.errors.proprietor_photo
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
                            />
                        </div>
                    </Card>

                    <Card
                        title="Contact Information"
                        icon={UserRound}
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <TextInput
                                label="Phone *"
                                value={
                                    form.data.phone
                                }
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

                            <TextInput
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

                            <TextInput
                                label="Email"
                                type="email"
                                value={
                                    form.data.email
                                }
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

                            <TextInput
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

                        <div className="mt-5">
                            <FieldShell
                                label="Address *"
                                error={
                                    form.errors.address
                                }
                            >
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
                                    className="min-h-28 w-full rounded-md border border-neutral-300 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-950"
                                />
                            </FieldShell>
                        </div>
                    </Card>

                    <Card
                        title="Legal Information"
                        icon={FileText}
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <TextInput
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

                            <TextInput
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

                            <TextInput
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

                            <TextInput
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
                        title="Commercial & Management"
                        icon={CreditCard}
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <TextInput
                                label="Credit Limit"
                                type="number"
                                value={String(
                                    form.data.credit_limit
                                )}
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

                            <TextInput
                                label="Payment Terms Days"
                                type="number"
                                value={String(
                                    form.data.payment_terms_days
                                )}
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

                            <FieldShell
                                label="Manager"
                                error={
                                    form.errors.manager_user_id
                                }
                            >
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
                                        Not Assigned
                                    </option>

                                    {managers.map(
                                        (manager) => (
                                            <option
                                                key={manager.id}
                                                value={manager.id}
                                            >
                                                {manager.name}
                                                {manager.email
                                                    ? ` (${manager.email})`
                                                    : ""}
                                            </option>
                                        )
                                    )}
                                </select>
                            </FieldShell>

                            <FieldShell
                                label="Status"
                                error={
                                    form.errors.is_active
                                }
                            >
                                <label className="flex h-10 items-center gap-3 rounded-md border border-neutral-300 px-3 dark:border-neutral-700">
                                    <input
                                        type="checkbox"
                                        checked={
                                            form.data.is_active
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                "is_active",
                                                event.target.checked
                                            )
                                        }
                                    />

                                    <span className="text-sm">
                                        Active Dealer
                                    </span>
                                </label>
                            </FieldShell>
                        </div>

                        <div className="mt-5">
                            <FieldShell
                                label="Notes"
                                error={
                                    form.errors.notes
                                }
                            >
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
                                    className="min-h-28 w-full rounded-md border border-neutral-300 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-950"
                                />
                            </FieldShell>
                        </div>
                    </Card>

                    <div className="flex flex-wrap justify-end gap-3">
                        <Link
                            href={`/erp/crm/dealers/${dealer.id}`}
                            className="inline-flex h-10 items-center justify-center rounded-md border border-neutral-200 px-4 text-sm font-medium dark:border-neutral-700"
                        >
                            Cancel
                        </Link>

                        <button
                            type="submit"
                            disabled={
                                form.processing
                            }
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-neutral-950 px-5 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-neutral-950"
                        >
                            <Save className="h-4 w-4" />

                            {form.processing
                                ? "Saving..."
                                : "Save Dealer"}
                        </button>
                    </div>
                </form>
            </div>
        </ERPLayout>
    );
}

function ProprietorPhotoField({
    dealerId,
    existingPhoto,
    file,
    error,
    onChange,
    onRemove,
}: {
    dealerId: number;
    existingPhoto?:
        | string
        | null;
    file:
        | File
        | null;
    error?: string;
    onChange: (
        event:
            ChangeEvent<HTMLInputElement>
    ) => void;
    onRemove: () => void;
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
            setPreviewUrl(null);
            return;
        }

        const url =
            URL.createObjectURL(
                file
            );

        setPreviewUrl(url);

        return () => {
            URL.revokeObjectURL(
                url
            );
        };
    }, [file]);

    const existingUrl =
        existingPhoto
            ? `/erp/crm/dealers/${dealerId}/proprietor-photo`
            : null;

    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium">
                Proprietor Picture
            </label>

            <div className="rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <div className="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        {previewUrl ||
                        existingUrl ? (
                            <img
                                src={
                                    previewUrl ??
                                    existingUrl ??
                                    ""
                                }
                                alt="Proprietor"
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <UserRound className="h-10 w-10 text-neutral-400" />
                        )}
                    </div>

                    <div className="flex-1">
                        <div className="flex items-center gap-2">
                            <Camera className="h-4 w-4 text-neutral-400" />

                            <p className="text-sm font-medium">
                                {file
                                    ? file.name
                                    : existingPhoto
                                      ? "Current proprietor picture"
                                      : "No proprietor picture"}
                            </p>
                        </div>

                        <p className="mt-1 text-xs text-neutral-500">
                            JPG, JPEG, PNG or WEBP. Maximum 2 MB.
                        </p>

                        <div className="mt-3 flex flex-wrap gap-2">
                            <label className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-neutral-200 bg-white px-3 text-xs font-medium transition hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800">
                                <Upload className="h-3.5 w-3.5" />

                                {file
                                    ? "Change Selected Picture"
                                    : existingPhoto
                                      ? "Replace Picture"
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
                                    className="inline-flex h-9 items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 text-xs font-medium text-red-600 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />

                                    Cancel New Picture
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {error && (
                <p className="mt-1 text-xs text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

function Card({
    title,
    icon: Icon,
    children,
}: {
    title: string;
    icon:
        typeof Building2;
    children:
        ReactNode;
}) {
    return (
        <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-950">
            <div className="mb-5 flex items-center gap-2">
                <Icon className="h-5 w-5 text-neutral-500" />

                <h2 className="font-semibold">
                    {title}
                </h2>
            </div>

            {children}
        </section>
    );
}

function FieldShell({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children:
        ReactNode;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium">
                {label}
            </label>

            {children}

            {error && (
                <p className="mt-1 text-xs text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

function TextInput({
    label,
    value,
    onChange,
    error,
    type = "text",
}: {
    label: string;
    value: string;
    onChange: (
        value: string
    ) => void;
    error?: string;
    type?: string;
}) {
    return (
        <FieldShell
            label={label}
            error={error}
        >
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
        </FieldShell>
    );
}

function MiniInfo({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div>
            <p className="text-xs uppercase tracking-wide text-neutral-400">
                {label}
            </p>

            <p className="mt-1 font-medium">
                {value}
            </p>
        </div>
    );
}

const inputClass =
    "h-10 w-full rounded-md border border-neutral-300 bg-white px-3 text-sm outline-none focus:border-neutral-500 dark:border-neutral-700 dark:bg-neutral-950";
