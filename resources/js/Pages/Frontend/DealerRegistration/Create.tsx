import {
    Head,
    useForm,
} from "@inertiajs/react";

import {
    useEffect,
    useMemo,
    useState,
} from "react";

import type {
    ChangeEvent,
    FormEvent,
} from "react";

import {
    Building2,
    ExternalLink,
    FileCheck2,
    FileText,
    ImageIcon,
    MapPin,
    Send,
    Trash2,
    Upload,
    UserRound,
} from "lucide-react";

import FrontendLayout from "@/Layouts/frontend-layout";

import type {
    DealerDepot,
    DealerUpazila,
} from "@/types/dealer";

type Props = {
    depots: DealerDepot[];
    upazilas: DealerUpazila[];
};

type DocumentField =
    | "bank_check"
    | "trade_license_document"
    | "nid_document"
    | "tin_document"
    | "vat_document";

type FormData = {
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

    business_address: string;
    postal_code: string;

    years_in_business: number | "";

    requested_credit_limit:
        number | "";

    business_description: string;

    bank_check: File | null;

    trade_license_document:
        File | null;

    nid_document: File | null;

    tin_document: File | null;

    vat_document: File | null;
};

const inputClass =
    "h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm outline-none transition focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 dark:border-neutral-700 dark:bg-neutral-950";

const textareaClass =
    "min-h-28 w-full rounded-lg border border-neutral-300 bg-white p-3 text-sm outline-none transition focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 dark:border-neutral-700 dark:bg-neutral-950";

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

export default function Create({
    depots,
    upazilas,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
        progress,
    } =
        useForm<FormData>({
            depot_id: "",
            upazila_id: "",

            business_name: "",
            proprietor_name: "",
            proprietor_photo: null,

            phone: "",
            alternate_phone: "",
            email: "",

            trade_license_number:
                "",

            nid_number: "",
            tin_number: "",
            vat_number: "",

            business_address: "",
            postal_code: "",

            years_in_business: "",

            requested_credit_limit:
                "",

            business_description:
                "",

            bank_check: null,

            trade_license_document:
                null,

            nid_document: null,

            tin_document: null,

            vat_document: null,
        });

    const selectedDepot =
        useMemo(
            () =>
                depots.find(
                    (depot) =>
                        depot.id ===
                        data.depot_id
                ),
            [
                data.depot_id,
                depots,
            ]
        );

    const availableUpazilas =
        useMemo(
            () => {
                if (
                    !selectedDepot
                ) {
                    return [];
                }

                return upazilas.filter(
                    (upazila) =>
                        upazila.district_id ===
                        selectedDepot.district_id
                );
            },
            [
                selectedDepot,
                upazilas,
            ]
        );

    const changeDepot = (
        value: string
    ) => {
        setData(
            "depot_id",
            value
                ? Number(value)
                : ""
        );

        setData(
            "upazila_id",
            ""
        );
    };

    const fileChange =
        (
            key:
                DocumentField
        ) =>
        (
            event:
                ChangeEvent<HTMLInputElement>
        ) => {
            setData(
                key,
                event.target.files?.[0] ??
                    null
            );

            /*
             * Clear the native input value.
             *
             * This allows the user to remove a file
             * and then select the exact same file again.
             */
            event.target.value =
                "";
        };

    const removeFile = (
        key: DocumentField
    ) => {
        setData(
            key,
            null
        );
    };

    const proprietorPhotoChange = (
        event: ChangeEvent<HTMLInputElement>
    ) => {
        setData(
            "proprietor_photo",
            event.target.files?.[0] ??
                null
        );

        event.target.value =
            "";
    };

    const removeProprietorPhoto = () => {
        setData(
            "proprietor_photo",
            null
        );
    };

    const submit = (
        event: FormEvent
    ) => {
        event.preventDefault();

        post(
            "/dealer-registration",
            {
                forceFormData:
                    true,

                preserveScroll:
                    true,
            }
        );
    };

    return (
        <FrontendLayout>
            <Head title="Dealer Registration" />

            <section className="bg-neutral-50 py-12 dark:bg-neutral-950">
                <div className="mx-auto max-w-5xl px-4 sm:px-6">
                    <div className="mb-8">
                        <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-neutral-200 bg-white px-3 py-1.5 text-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <Building2 className="h-4 w-4" />

                            Dealer Registration
                        </div>

                        <h1 className="text-3xl font-bold">
                            Apply to become a Dealer
                        </h1>

                        <p className="mt-3 max-w-2xl text-neutral-500">
                            Choose the Depot you want
                            to operate under and your
                            Upazila. Your application
                            and documents will be
                            reviewed before approval.
                        </p>
                    </div>

                    <form
                        onSubmit={
                            submit
                        }
                        className="space-y-6"
                    >
                        {/* Distribution Area */}
                        <section className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="mb-5 flex items-center gap-2">
                                <MapPin className="h-5 w-5" />

                                <h2 className="font-semibold">
                                    Distribution Area
                                </h2>
                            </div>

                            <div className="grid gap-5 md:grid-cols-2">
                                <div>
                                    <label className={labelClass}>
                                        Depot *
                                    </label>

                                    <select
                                        value={
                                            data.depot_id
                                        }
                                        onChange={(
                                            event
                                        ) =>
                                            changeDepot(
                                                event
                                                    .target
                                                    .value
                                            )
                                        }
                                        className={
                                            inputClass
                                        }
                                    >
                                        <option value="">
                                            Select Depot
                                        </option>

                                        {depots.map(
                                            (
                                                depot
                                            ) => (
                                                <option
                                                    key={
                                                        depot.id
                                                    }
                                                    value={
                                                        depot.id
                                                    }
                                                >
                                                    {
                                                        depot.name
                                                    }

                                                    {depot
                                                        .district
                                                        ?.name
                                                        ? ` — ${depot.district.name}`
                                                        : ""}
                                                </option>
                                            )
                                        )}
                                    </select>

                                    <ErrorText
                                        message={
                                            errors.depot_id
                                        }
                                    />
                                </div>

                                <div>
                                    <label className={labelClass}>
                                        Upazila *
                                    </label>

                                    <select
                                        value={
                                            data.upazila_id
                                        }
                                        disabled={
                                            !selectedDepot
                                        }
                                        onChange={(
                                            event
                                        ) =>
                                            setData(
                                                "upazila_id",
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
                                            {selectedDepot
                                                ? "Select Upazila"
                                                : "Select Depot first"}
                                        </option>

                                        {availableUpazilas.map(
                                            (
                                                upazila
                                            ) => (
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

                                    <ErrorText
                                        message={
                                            errors.upazila_id
                                        }
                                    />
                                </div>
                            </div>

                            {selectedDepot && (
                                <div className="mt-5 rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm dark:border-neutral-700 dark:bg-neutral-950">
                                    <div className="flex items-start gap-3">
                                        <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-neutral-500" />

                                        <div>
                                            <p className="font-medium">
                                                Selected
                                                Distribution
                                                Area
                                            </p>

                                            <p className="mt-1 text-neutral-500">
                                                {
                                                    selectedDepot.name
                                                }

                                                {selectedDepot
                                                    .district
                                                    ?.name
                                                    ? ` — ${selectedDepot.district.name}`
                                                    : ""}

                                                {selectedDepot
                                                    .district
                                                    ?.division
                                                    ?.name
                                                    ? `, ${selectedDepot.district.division.name}`
                                                    : ""}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </section>

                        {/* Business Information */}
                        <section className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                            <h2 className="mb-5 font-semibold">
                                Business Information
                            </h2>

                            <div className="grid gap-5 md:grid-cols-2">
                                <TextField
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

                                <TextField
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

                                <div className="md:col-span-2">
                                    <ProprietorPhotoField
                                        file={
                                            data.proprietor_photo
                                        }
                                        onChange={
                                            proprietorPhotoChange
                                        }
                                        onRemove={
                                            removeProprietorPhoto
                                        }
                                        error={
                                            errors.proprietor_photo
                                        }
                                    />
                                </div>

                                <TextField
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

                                <TextField
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

                                <TextField
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

                                <TextField
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

                                <TextField
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

                                <TextField
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

                                <TextField
                                    label="VAT / BIN"
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

                                <div>
                                    <label className={labelClass}>
                                        Years in Business
                                    </label>

                                    <input
                                        type="number"
                                        min="0"
                                        value={
                                            data.years_in_business
                                        }
                                        onChange={(
                                            event
                                        ) =>
                                            setData(
                                                "years_in_business",
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
                                            errors.years_in_business
                                        }
                                    />
                                </div>

                                <div>
                                    <label className={labelClass}>
                                        Requested Credit Limit
                                    </label>

                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={
                                            data.requested_credit_limit
                                        }
                                        onChange={(
                                            event
                                        ) =>
                                            setData(
                                                "requested_credit_limit",
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
                                            errors.requested_credit_limit
                                        }
                                    />
                                </div>

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

                                <TextField
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

                                <div className="md:col-span-2">
                                    <label className={labelClass}>
                                        Business Description
                                    </label>

                                    <textarea
                                        value={
                                            data.business_description
                                        }
                                        onChange={(
                                            event
                                        ) =>
                                            setData(
                                                "business_description",
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
                                            errors.business_description
                                        }
                                    />
                                </div>
                            </div>
                        </section>

                        {/* Documents */}
                        <section className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="mb-5 flex items-start gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-800">
                                    <FileCheck2 className="h-5 w-5" />
                                </div>

                                <div>
                                    <h2 className="font-semibold">
                                        Documents
                                    </h2>

                                    <p className="mt-1 text-xs leading-5 text-neutral-500">
                                        Upload PDF, JPG or PNG
                                        documents. Maximum 5 MB
                                        each. You can preview and
                                        remove each document before
                                        submitting the application.
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-5 md:grid-cols-2">
                                <FileField
                                    label="Bank Cheque *"
                                    file={
                                        data.bank_check
                                    }
                                    onChange={fileChange(
                                        "bank_check"
                                    )}
                                    onRemove={() =>
                                        removeFile(
                                            "bank_check"
                                        )
                                    }
                                    error={
                                        errors.bank_check
                                    }
                                />

                                <FileField
                                    label="Trade License *"
                                    file={
                                        data.trade_license_document
                                    }
                                    onChange={fileChange(
                                        "trade_license_document"
                                    )}
                                    onRemove={() =>
                                        removeFile(
                                            "trade_license_document"
                                        )
                                    }
                                    error={
                                        errors.trade_license_document
                                    }
                                />

                                <FileField
                                    label="NID / ID Card *"
                                    file={
                                        data.nid_document
                                    }
                                    onChange={fileChange(
                                        "nid_document"
                                    )}
                                    onRemove={() =>
                                        removeFile(
                                            "nid_document"
                                        )
                                    }
                                    error={
                                        errors.nid_document
                                    }
                                />

                                <FileField
                                    label="TIN Document"
                                    file={
                                        data.tin_document
                                    }
                                    onChange={fileChange(
                                        "tin_document"
                                    )}
                                    onRemove={() =>
                                        removeFile(
                                            "tin_document"
                                        )
                                    }
                                    error={
                                        errors.tin_document
                                    }
                                />

                                <FileField
                                    label="VAT / BIN Document"
                                    file={
                                        data.vat_document
                                    }
                                    onChange={fileChange(
                                        "vat_document"
                                    )}
                                    onRemove={() =>
                                        removeFile(
                                            "vat_document"
                                        )
                                    }
                                    error={
                                        errors.vat_document
                                    }
                                />
                            </div>

                            {progress && (
                                <div className="mt-6 rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
                                    <div className="mb-2 flex justify-between text-xs">
                                        <span className="font-medium">
                                            Uploading documents
                                        </span>

                                        <span>
                                            {
                                                progress.percentage
                                            }
                                            %
                                        </span>
                                    </div>

                                    <div className="h-2 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
                                        <div
                                            className="h-full bg-neutral-950 transition-all dark:bg-white"
                                            style={{
                                                width: `${progress.percentage}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            )}
                        </section>

                        {/* Submit */}
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-xs leading-5 text-neutral-500">
                                By submitting this form,
                                your application and
                                documents will be sent
                                for administrative review.
                            </p>

                            <button
                                type="submit"
                                disabled={
                                    processing
                                }
                                className="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-lg bg-neutral-950 px-6 font-medium text-white transition hover:bg-neutral-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-neutral-950 dark:hover:bg-neutral-200"
                            >
                                <Send className="h-4 w-4" />

                                {processing
                                    ? "Submitting..."
                                    : "Submit Dealer Application"}
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </FrontendLayout>
    );
}

function TextField({
    label,
    value,
    onChange,
    type = "text",
    error,
}: {
    label: string;
    value: string;

    onChange:
        (value: string) => void;

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
    onChange,
    onRemove,
    error,
}: {
    file: File | null;

    onChange: (
        event: ChangeEvent<HTMLInputElement>
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

    return (
        <div>
            <label className={labelClass}>
                Proprietor Picture
            </label>

            <div className="rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <div className="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        {previewUrl ? (
                            <img
                                src={
                                    previewUrl
                                }
                                alt="Proprietor preview"
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <UserRound className="h-10 w-10 text-neutral-400" />
                        )}
                    </div>

                    <div className="flex-1">
                        <p className="text-sm font-medium">
                            {file
                                ? file.name
                                : "No proprietor picture selected"}
                        </p>

                        <p className="mt-1 text-xs text-neutral-500">
                            JPG, JPEG, PNG or WEBP. Maximum 2 MB.
                        </p>

                        {file && (
                            <p className="mt-1 text-xs text-neutral-500">
                                {formatFileSize(
                                    file.size
                                )}
                            </p>
                        )}

                        <div className="mt-3 flex flex-wrap gap-2">
                            <label className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-neutral-200 bg-white px-3 text-xs font-medium transition hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800">
                                <Upload className="h-3.5 w-3.5" />

                                {file
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
                                    className="inline-flex h-9 items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 text-xs font-medium text-red-600 transition hover:bg-red-100 dark:border-red-900 dark:bg-red-950/30 dark:text-red-400"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />

                                    Remove
                                </button>
                            )}
                        </div>
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

function FileField({
    label,
    file,
    onChange,
    onRemove,
    error,
}: {
    label: string;

    file:
        File | null;

    onChange:
        (
            event:
                ChangeEvent<HTMLInputElement>
        ) => void;

    onRemove:
        () => void;

    error?: string;
}) {
    return (
        <div>
            <label className={labelClass}>
                {label}
            </label>

            {!file ? (
                <label className="group flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-neutral-300 bg-neutral-50 p-5 text-center transition hover:border-neutral-500 hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-950 dark:hover:border-neutral-500 dark:hover:bg-neutral-900">
                    <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-full border border-neutral-200 bg-white transition group-hover:scale-105 dark:border-neutral-700 dark:bg-neutral-900">
                        <Upload className="h-4 w-4" />
                    </div>

                    <span className="text-sm font-medium">
                        Choose Document
                    </span>

                    <span className="mt-1 text-xs text-neutral-500">
                        PDF, JPG or PNG · Max 5 MB
                    </span>

                    <input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                        onChange={
                            onChange
                        }
                        className="hidden"
                    />
                </label>
            ) : (
                <DocumentPreview
                    file={file}
                    onChange={
                        onChange
                    }
                    onRemove={
                        onRemove
                    }
                />
            )}

            <ErrorText
                message={error}
            />
        </div>
    );
}

function DocumentPreview({
    file,
    onChange,
    onRemove,
}: {
    file: File;

    onChange:
        (
            event:
                ChangeEvent<HTMLInputElement>
        ) => void;

    onRemove:
        () => void;
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

    const isImage =
        file.type.startsWith(
            "image/"
        );

    const isPdf =
        file.type ===
            "application/pdf" ||
        file.name
            .toLowerCase()
            .endsWith(".pdf");

    return (
        <div className="overflow-hidden rounded-lg border border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-950">
            {/* File header */}
            <div className="flex items-start justify-between gap-3 border-b border-neutral-200 p-3 dark:border-neutral-700">
                <div className="flex min-w-0 items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white dark:bg-neutral-900">
                        {isImage ? (
                            <ImageIcon className="h-5 w-5" />
                        ) : (
                            <FileText className="h-5 w-5" />
                        )}
                    </div>

                    <div className="min-w-0">
                        <p
                            className="truncate text-sm font-medium"
                            title={
                                file.name
                            }
                        >
                            {file.name}
                        </p>

                        <p className="mt-0.5 text-xs text-neutral-500">
                            {formatFileSize(
                                file.size
                            )}

                            {" · "}

                            {isPdf
                                ? "PDF"
                                : isImage
                                  ? "Image"
                                  : file.type ||
                                    "Document"}
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    onClick={
                        onRemove
                    }
                    title="Remove document"
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-neutral-500 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/30"
                >
                    <Trash2 className="h-4 w-4" />
                </button>
            </div>

            {/* Image preview */}
            {isImage &&
                previewUrl && (
                    <div className="flex h-64 items-center justify-center bg-white p-3 dark:bg-neutral-900">
                        <img
                            src={
                                previewUrl
                            }
                            alt={
                                file.name
                            }
                            className="max-h-full max-w-full rounded object-contain"
                        />
                    </div>
                )}

            {/* PDF preview */}
            {isPdf &&
                previewUrl && (
                    <div className="bg-white dark:bg-neutral-900">
                        <iframe
                            src={
                                previewUrl
                            }
                            title={`Preview ${file.name}`}
                            className="h-80 w-full border-0"
                        />
                    </div>
                )}

            {/* Unsupported preview fallback */}
            {!isImage &&
                !isPdf && (
                    <div className="flex min-h-36 flex-col items-center justify-center p-5 text-center">
                        <FileText className="mb-2 h-8 w-8 text-neutral-400" />

                        <p className="text-sm font-medium">
                            Preview unavailable
                        </p>

                        <p className="mt-1 text-xs text-neutral-500">
                            You can still submit this
                            document.
                        </p>
                    </div>
                )}

            {/* Actions */}
            <div className="flex flex-wrap items-center gap-2 border-t border-neutral-200 p-3 dark:border-neutral-700">
                {previewUrl && (
                    <a
                        href={
                            previewUrl
                        }
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex h-9 items-center gap-2 rounded-md border border-neutral-200 bg-white px-3 text-xs font-medium transition hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                    >
                        <ExternalLink className="h-3.5 w-3.5" />

                        Open Preview
                    </a>
                )}

                <label className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-neutral-200 bg-white px-3 text-xs font-medium transition hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800">
                    <Upload className="h-3.5 w-3.5" />

                    Change

                    <input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                        onChange={
                            onChange
                        }
                        className="hidden"
                    />
                </label>

                <button
                    type="button"
                    onClick={
                        onRemove
                    }
                    className="inline-flex h-9 items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 text-xs font-medium text-red-600 transition hover:bg-red-100 dark:border-red-900 dark:bg-red-950/30 dark:text-red-400"
                >
                    <Trash2 className="h-3.5 w-3.5" />

                    Remove
                </button>
            </div>
        </div>
    );
}

function formatFileSize(
    bytes: number
) {
    if (
        bytes === 0
    ) {
        return "0 B";
    }

    if (
        bytes < 1024
    ) {
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
