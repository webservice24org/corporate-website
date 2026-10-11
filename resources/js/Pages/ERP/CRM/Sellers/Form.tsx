import {
    useEffect,
    useMemo,
    useState,
} from "react";

import type {
    ChangeEvent,
} from "react";

import {
    Building2,
    MapPin,
    Trash2,
    Upload,
    UserRound,
} from "lucide-react";

import type {
    SellerFormDealer,
    SellerFormManager,
} from "@/types/seller";

type SellerFormProps = {
    data: SellerFormData;

    setData: <K extends keyof SellerFormData>(
        key: K,
        value: SellerFormData[K]
    ) => void;

    errors: Partial<
        Record<
            keyof SellerFormData,
            string
        >
    >;
};

export type SellerFormData = {
    dealer_id:
        number | "";

    business_name:
        string;

    proprietor_name:
        string;

    proprietor_photo?:
        File | null;

    seller_type:
        "retailer"
        | "general_seller";

    phone:
        string;

    alternate_phone:
        string;

    email:
        string;

    trade_license_number:
        string;

    nid_number:
        string;

    tin_number:
        string;

    vat_number:
        string;

    business_address:
        string;

    postal_code:
        string;

    credit_limit:
        number | "";

    payment_terms_days:
        number | "";

    manager_user_id:
        number | "";

    is_active:
        boolean;

    notes:
        string;
};



type Props = {
    form: SellerFormProps;

    dealers:
        SellerFormDealer[];

    managers:
        SellerFormManager[];

    existingProprietorPhoto?:
        string | null;
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

export default function Form({
    form,
    dealers,
    managers,
    existingProprietorPhoto = null,
}: Props) {
    const {
        data,
        setData,
        errors,
    } = form;

    const selectedDealer =
        useMemo(
            () =>
                dealers.find(
                    (dealer) =>
                        dealer.id ===
                        data.dealer_id
                ),
            [
                dealers,
                data.dealer_id,
            ]
        );

    return (
        <div className="space-y-6">
            <section className="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div className="mb-5 flex items-center gap-2">
                    <Building2 className="h-5 w-5" />

                    <h2 className="font-semibold">
                        Dealer Assignment
                    </h2>
                </div>

                <div>
                    <label className={labelClass}>
                        Dealer *
                    </label>

                    <select
                        value={
                            data.dealer_id
                        }
                        onChange={(
                            event
                        ) =>
                            setData(
                                "dealer_id",
                                event.target.value
                                    ? Number(
                                          event.target.value
                                      )
                                    : ""
                            )
                        }
                        className={
                            inputClass
                        }
                    >
                        <option value="">
                            Select Dealer
                        </option>

                        {dealers.map(
                            (dealer) => (
                                <option
                                    key={
                                        dealer.id
                                    }
                                    value={
                                        dealer.id
                                    }
                                >
                                    {
                                        dealer.business_name
                                    }

                                    {dealer.dealer_code
                                        ? ` (${dealer.dealer_code})`
                                        : ""}

                                    {dealer.depot
                                        ?.name
                                        ? ` - ${dealer.depot.name}`
                                        : ""}

                                    {dealer.upazila
                                        ?.name
                                        ? ` - ${dealer.upazila.name}`
                                        : ""}
                                </option>
                            )
                        )}
                    </select>

                    <ErrorText
                        message={
                            errors.dealer_id
                        }
                    />
                </div>

                {selectedDealer && (
                    <div className="mt-4 rounded-md bg-neutral-50 p-4 text-sm dark:bg-neutral-950">
                        <div className="flex gap-3">
                            <MapPin className="mt-0.5 h-4 w-4 shrink-0" />

                            <div>
                                <p className="font-medium">
                                    Distribution hierarchy
                                </p>

                                <p className="mt-1 text-neutral-500">
                                    {selectedDealer
                                        .company
                                        ?.name ??
                                        "Company"}

                                    {" → "}

                                    {selectedDealer
                                        .depot
                                        ?.name ??
                                        "Depot"}

                                    {" → "}

                                    {
                                        selectedDealer.business_name
                                    }

                                    {" → Seller"}

                                    {selectedDealer
                                        .upazila
                                        ?.name
                                        ? ` (${selectedDealer.upazila.name})`
                                        : ""}
                                </p>
                            </div>
                        </div>
                    </div>
                )}
            </section>

            <section className="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <h2 className="mb-5 font-semibold">
                    Seller Information
                </h2>

                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="Business Name *"
                        value={
                            data.business_name
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "business_name",
                                value
                            )
                        }
                        error={
                            errors.business_name
                        }
                    />

                    <Field
                        label="Proprietor Name *"
                        value={
                            data.proprietor_name
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "proprietor_name",
                                value
                            )
                        }
                        error={
                            errors.proprietor_name
                        }
                    />

                    <ProprietorPhotoField
                        file={
                            data.proprietor_photo ??
                            null
                        }
                        existingPhoto={
                            existingProprietorPhoto
                        }
                        onChange={(
                            event
                        ) => {
                            setData(
                                "proprietor_photo",
                                event.target.files?.[0] ??
                                    null
                            );

                            event.target.value =
                                "";
                        }}
                        onRemove={() =>
                            setData(
                                "proprietor_photo",
                                null
                            )
                        }
                        error={
                            errors.proprietor_photo
                        }
                    />

                    <div>
                        <label className={labelClass}>
                            Seller Type *
                        </label>

                        <select
                            value={
                                data.seller_type
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "seller_type",
                                    event
                                        .target
                                        .value as
                                        | "retailer"
                                        | "general_seller"
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="retailer">
                                Retailer
                            </option>

                            <option value="general_seller">
                                General Seller
                            </option>
                        </select>

                        <ErrorText
                            message={
                                errors.seller_type
                            }
                        />
                    </div>

                    <Field
                        label="Phone *"
                        value={
                            data.phone
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "phone",
                                value
                            )
                        }
                        error={
                            errors.phone
                        }
                    />

                    <Field
                        label="Alternate Phone"
                        value={
                            data.alternate_phone
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "alternate_phone",
                                value
                            )
                        }
                        error={
                            errors.alternate_phone
                        }
                    />

                    <Field
                        label="Email"
                        type="email"
                        value={
                            data.email
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "email",
                                value
                            )
                        }
                        error={
                            errors.email
                        }
                    />

                    <Field
                        label="Trade License Number"
                        value={
                            data.trade_license_number
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "trade_license_number",
                                value
                            )
                        }
                        error={
                            errors.trade_license_number
                        }
                    />

                    <Field
                        label="NID Number"
                        value={
                            data.nid_number
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "nid_number",
                                value
                            )
                        }
                        error={
                            errors.nid_number
                        }
                    />

                    <Field
                        label="TIN Number"
                        value={
                            data.tin_number
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "tin_number",
                                value
                            )
                        }
                        error={
                            errors.tin_number
                        }
                    />

                    <Field
                        label="VAT / BIN Number"
                        value={
                            data.vat_number
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "vat_number",
                                value
                            )
                        }
                        error={
                            errors.vat_number
                        }
                    />

                    <div className="md:col-span-2">
                        <label className={labelClass}>
                            Business Address *
                        </label>

                        <textarea
                            value={
                                data.business_address
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "business_address",
                                    event
                                        .target
                                        .value
                                )
                            }
                            className={
                                textareaClass
                            }
                        />

                        <ErrorText
                            message={
                                errors.business_address
                            }
                        />
                    </div>

                    <Field
                        label="Postal Code"
                        value={
                            data.postal_code
                        }
                        onChange={(
                            value
                        ) =>
                            setData(
                                "postal_code",
                                value
                            )
                        }
                        error={
                            errors.postal_code
                        }
                    />
                </div>
            </section>

            <section className="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <h2 className="mb-5 font-semibold">
                    Commercial Settings
                </h2>

                <div className="grid gap-5 md:grid-cols-2">
                    <div>
                        <label className={labelClass}>
                            Credit Limit
                        </label>

                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={
                                data.credit_limit
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "credit_limit",
                                    event
                                        .target
                                        .value
                                        ? Number(
                                              event
                                                  .target
                                                  .value
                                          )
                                        : ""
                                )
                            }
                            className={
                                inputClass
                            }
                        />

                        <ErrorText
                            message={
                                errors.credit_limit
                            }
                        />
                    </div>

                    <div>
                        <label className={labelClass}>
                            Payment Terms
                            (Days)
                        </label>

                        <input
                            type="number"
                            min="0"
                            value={
                                data.payment_terms_days
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "payment_terms_days",
                                    event
                                        .target
                                        .value
                                        ? Number(
                                              event
                                                  .target
                                                  .value
                                          )
                                        : ""
                                )
                            }
                            className={
                                inputClass
                            }
                        />

                        <ErrorText
                            message={
                                errors.payment_terms_days
                            }
                        />
                    </div>

                    <div>
                        <label className={labelClass}>
                            Manager /
                            Responsible User
                        </label>

                        <select
                            value={
                                data.manager_user_id
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "manager_user_id",
                                    event
                                        .target
                                        .value
                                        ? Number(
                                              event
                                                  .target
                                                  .value
                                          )
                                        : ""
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                No manager
                            </option>

                            {managers.map(
                                (
                                    manager
                                ) => (
                                    <option
                                        key={
                                            manager.id
                                        }
                                        value={
                                            manager.id
                                        }
                                    >
                                        {
                                            manager.name
                                        }

                                        {manager.email
                                            ? ` - ${manager.email}`
                                            : ""}
                                    </option>
                                )
                            )}
                        </select>

                        <ErrorText
                            message={
                                errors.manager_user_id
                            }
                        />
                    </div>

                    <div>
                        <label className={labelClass}>
                            Status
                        </label>

                        <select
                            value={
                                data.is_active
                                    ? "1"
                                    : "0"
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "is_active",
                                    event
                                        .target
                                        .value ===
                                        "1"
                                )
                            }
                            className={
                                inputClass
                            }
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
                                errors.is_active
                            }
                        />
                    </div>

                    <div className="md:col-span-2">
                        <label className={labelClass}>
                            Notes
                        </label>

                        <textarea
                            value={
                                data.notes
                            }
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
                            className={
                                textareaClass
                            }
                        />

                        <ErrorText
                            message={
                                errors.notes
                            }
                        />
                    </div>
                </div>
            </section>
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
    file:
        File | null;

    existingPhoto?:
        string | null;

    onChange: (
        event:
            ChangeEvent<HTMLInputElement>
    ) => void;

    onRemove:
        () => void;

    error?:
        string;
}) {
    const [
        previewUrl,
        setPreviewUrl,
    ] =
        useState<string | null>(
            null
        );

    useEffect(
        () => {
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
        },
        [
            file,
        ]
    );

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
                    <div className="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        {displayedPhoto ? (
                            <img
                                src={
                                    displayedPhoto
                                }
                                alt="Proprietor"
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <UserRound className="h-8 w-8 text-neutral-400" />
                        )}
                    </div>

                    <div className="min-w-0 flex-1">
                        {file && (
                            <div className="mb-3">
                                <p
                                    className="truncate text-sm font-medium"
                                    title={
                                        file.name
                                    }
                                >
                                    {
                                        file.name
                                    }
                                </p>

                                <p className="mt-1 text-xs text-neutral-500">
                                    {formatFileSize(
                                        file.size
                                    )}
                                </p>
                            </div>
                        )}

                        {!file &&
                            existingPhoto && (
                                <p className="mb-3 text-sm text-neutral-500">
                                    Existing proprietor picture
                                </p>
                            )}

                        <div className="flex flex-wrap gap-2">
                            <label className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-neutral-200 bg-white px-3 text-sm font-medium transition hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800">
                                <Upload className="h-4 w-4" />

                                {displayedPhoto
                                    ? "Change Picture"
                                    : "Choose Picture"}

                                <input
                                    type="file"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                    onChange={
                                        onChange
                                    }
                                    className="hidden"
                                />
                            </label>

                            {file && (
                                <button
                                    type="button"
                                    onClick={
                                        onRemove
                                    }
                                    className="inline-flex h-9 items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 text-sm font-medium text-red-600 transition hover:bg-red-100 dark:border-red-900 dark:bg-red-950/30 dark:text-red-400"
                                >
                                    <Trash2 className="h-4 w-4" />

                                    Cancel New Picture
                                </button>
                            )}
                        </div>

                        <p className="mt-2 text-xs text-neutral-500">
                            JPG, PNG or WEBP. Maximum 2 MB.
                        </p>
                    </div>
                </div>
            </div>

            <ErrorText
                message={
                    error
                }
            />
        </div>
    );
}

function formatFileSize(
    bytes: number
) {
    if (bytes === 0) {
        return "0 B";
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (
        bytes <
        1024 * 1024
    ) {
        return `${(
            bytes / 1024
        ).toFixed(1)} KB`;
    }

    return `${(
        bytes /
        1024 /
        1024
    ).toFixed(1)} MB`;
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
    onChange: (
        value: string
    ) => void;
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
                onChange={(
                    event
                ) =>
                    onChange(
                        event.target.value
                    )
                }
                className={
                    inputClass
                }
            />

            <ErrorText
                message={
                    error
                }
            />
        </div>
    );
}