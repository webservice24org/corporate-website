import {
    Head,
    useForm,
} from "@inertiajs/react";

import {
    Building2,
    ExternalLink,
    FileCheck2,
    FileText,
    ImageIcon,
    Landmark,
    MapPin,
    Send,
    ShieldCheck,
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
} from "react";

import FrontendLayout from "@/Layouts/frontend-layout";

import type {
    CompanyOption,
    DistrictOption,
    DivisionOption,
} from "@/types/depot";

type Props = {
    companies: CompanyOption[];
    divisions: DivisionOption[];
    districts: DistrictOption[];
};

type DocumentField =
    | "bank_check"
    | "trade_license_document"
    | "nid_document"
    | "tin_document"
    | "vat_document";

type FormData = {
    company_id: number | "";
    division_id: number | "";
    district_id: number | "";

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
    "h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm outline-none transition focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white";

const textareaClass =
    "min-h-28 w-full rounded-lg border border-neutral-300 bg-white px-3 py-3 text-sm outline-none transition focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 dark:border-neutral-700 dark:bg-neutral-950 dark:focus:border-white";

const labelClass =
    "mb-1.5 block text-sm font-medium text-neutral-800 dark:text-neutral-200";

function ErrorText({
    message,
}: {
    message?: string;
}) {
    if (!message) {
        return null;
    }

    return (
        <p className="mt-1 text-xs text-red-600">
            {message}
        </p>
    );
}

export default function Create({
    companies,
    divisions,
    districts,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
        progress,
        reset,
    } =
        useForm<FormData>({
            company_id: "",
            division_id: "",
            district_id: "",

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

    const availableDistricts =
        useMemo(
            () => {
                if (
                    data.division_id ===
                    ""
                ) {
                    return [];
                }

                return districts.filter(
                    (district) =>
                        district.division_id ===
                        data.division_id
                );
            },
            [
                data.division_id,
                districts,
            ]
        );

    const selectedCompany =
        useMemo(
            () =>
                companies.find(
                    (company) =>
                        company.id ===
                        data.company_id
                ),
            [
                companies,
                data.company_id,
            ]
        );

    const selectedDivision =
        useMemo(
            () =>
                divisions.find(
                    (division) =>
                        division.id ===
                        data.division_id
                ),
            [
                divisions,
                data.division_id,
            ]
        );

    const selectedDistrict =
        useMemo(
            () =>
                districts.find(
                    (district) =>
                        district.id ===
                        data.district_id
                ),
            [
                districts,
                data.district_id,
            ]
        );

    const changeDivision = (
        value: string
    ) => {
        setData(
            "division_id",
            value
                ? Number(value)
                : ""
        );

        setData(
            "district_id",
            ""
        );
    };

    const fileHandler =
        (
            field:
                DocumentField
        ) =>
        (
            event:
                ChangeEvent<HTMLInputElement>
        ) => {
            setData(
                field,
                event.target.files?.[0] ??
                    null
            );

            /*
             * Clearing the native input lets the
             * applicant choose the exact same file
             * again after removing it.
             */
            event.target.value =
                "";
        };

    const removeFile = (
        field:
            DocumentField
    ) => {
        setData(
            field,
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
        event:
            FormEvent
    ) => {
        event.preventDefault();

        post(
            "/depot-registration",
            {
                forceFormData:
                    true,

                preserveScroll:
                    true,

                onSuccess:
                    () => {
                        reset();
                    },
            }
        );
    };

    return (
        <FrontendLayout>
            <Head title="Depot Registration" />

            <section className="bg-neutral-50 py-12 dark:bg-neutral-950">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-8 max-w-3xl">
                        <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-neutral-200 bg-white px-3 py-1.5 text-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <Building2 className="h-4 w-4" />

                            Depot Registration
                        </div>

                        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                            Apply to become a Depot
                        </h1>

                        <p className="mt-3 leading-7 text-neutral-600 dark:text-neutral-400">
                            Submit your business
                            information and required
                            documents. Your application
                            will be reviewed by our
                            administration team before
                            approval.
                        </p>
                    </div>

                    <div className="grid gap-8 lg:grid-cols-[1fr_320px]">
                        <form
                            onSubmit={
                                submit
                            }
                            className="space-y-8"
                        >
                            {/* Organization */}
                            <div className="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                                <div className="mb-6 flex items-center gap-3">
                                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-800">
                                        <Landmark className="h-5 w-5" />
                                    </div>

                                    <div>
                                        <h2 className="font-semibold">
                                            Organization
                                        </h2>

                                        <p className="text-sm text-neutral-500">
                                            Select company
                                            and proposed
                                            Depot area.
                                        </p>
                                    </div>
                                </div>

                                <div className="grid gap-5 md:grid-cols-2">
                                    <div className="md:col-span-2">
                                        <label className={labelClass}>
                                            Company *
                                        </label>

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
                                                Select company
                                            </option>

                                            {companies.map(
                                                (
                                                    company
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
                                                )
                                            )}
                                        </select>

                                        <ErrorText
                                            message={
                                                errors.company_id
                                            }
                                        />
                                    </div>

                                    <div>
                                        <label className={labelClass}>
                                            Division *
                                        </label>

                                        <select
                                            value={
                                                data.division_id
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                changeDivision(
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
                                                Select division
                                            </option>

                                            {divisions.map(
                                                (
                                                    division
                                                ) => (
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
                                                    </option>
                                                )
                                            )}
                                        </select>

                                        <ErrorText
                                            message={
                                                errors.division_id
                                            }
                                        />
                                    </div>

                                    <div>
                                        <label className={labelClass}>
                                            District *
                                        </label>

                                        <select
                                            value={
                                                data.district_id
                                            }
                                            disabled={
                                                !data.division_id
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                setData(
                                                    "district_id",
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
                                                {data.division_id
                                                    ? "Select district"
                                                    : "Select division first"}
                                            </option>

                                            {availableDistricts.map(
                                                (
                                                    district
                                                ) => (
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
                                                    </option>
                                                )
                                            )}
                                        </select>

                                        <ErrorText
                                            message={
                                                errors.district_id
                                            }
                                        />
                                    </div>
                                </div>

                                {(selectedCompany ||
                                    selectedDivision ||
                                    selectedDistrict) && (
                                    <div className="mt-5 rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
                                        <div className="flex items-start gap-3">
                                            <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-neutral-500" />

                                            <div>
                                                <p className="text-sm font-medium">
                                                    Proposed
                                                    Distribution
                                                    Area
                                                </p>

                                                <p className="mt-1 text-sm text-neutral-500">
                                                    {selectedCompany
                                                        ?.name ??
                                                        "Company not selected"}

                                                    {selectedDivision
                                                        ? ` — ${selectedDivision.name}`
                                                        : ""}

                                                    {selectedDistrict
                                                        ? ` — ${selectedDistrict.name}`
                                                        : ""}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Business Information */}
                            <div className="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                                <h2 className="mb-6 font-semibold">
                                    Business Information
                                </h2>

                                <div className="grid gap-5 md:grid-cols-2">
                                    <div>
                                        <label className={labelClass}>
                                            Business Name *
                                        </label>

                                        <input
                                            value={
                                                data.business_name
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                setData(
                                                    "business_name",
                                                    event
                                                        .target
                                                        .value
                                                )
                                            }
                                            className={
                                                inputClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors.business_name
                                            }
                                        />
                                    </div>

                                    <div>
                                        <label className={labelClass}>
                                            Proprietor Name *
                                        </label>

                                        <input
                                            value={
                                                data.proprietor_name
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                setData(
                                                    "proprietor_name",
                                                    event
                                                        .target
                                                        .value
                                                )
                                            }
                                            className={
                                                inputClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors.proprietor_name
                                            }
                                        />
                                    </div>


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

                                    <div>
                                        <label className={labelClass}>
                                            Phone *
                                        </label>

                                        <input
                                            value={
                                                data.phone
                                            }
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
                                            className={
                                                inputClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors.phone
                                            }
                                        />
                                    </div>

                                    <div>
                                        <label className={labelClass}>
                                            Alternate Phone
                                        </label>

                                        <input
                                            value={
                                                data.alternate_phone
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                setData(
                                                    "alternate_phone",
                                                    event
                                                        .target
                                                        .value
                                                )
                                            }
                                            className={
                                                inputClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors.alternate_phone
                                            }
                                        />
                                    </div>

                                    <div className="md:col-span-2">
                                        <label className={labelClass}>
                                            Email
                                        </label>

                                        <input
                                            type="email"
                                            value={
                                                data.email
                                            }
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
                                            className={
                                                inputClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors.email
                                            }
                                        />
                                    </div>

                                    <div>
                                        <label className={labelClass}>
                                            Trade License Number
                                        </label>

                                        <input
                                            value={
                                                data.trade_license_number
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                setData(
                                                    "trade_license_number",
                                                    event
                                                        .target
                                                        .value
                                                )
                                            }
                                            className={
                                                inputClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors.trade_license_number
                                            }
                                        />
                                    </div>

                                    <div>
                                        <label className={labelClass}>
                                            NID Number
                                        </label>

                                        <input
                                            value={
                                                data.nid_number
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                setData(
                                                    "nid_number",
                                                    event
                                                        .target
                                                        .value
                                                )
                                            }
                                            className={
                                                inputClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors.nid_number
                                            }
                                        />
                                    </div>

                                    <div>
                                        <label className={labelClass}>
                                            TIN Number
                                        </label>

                                        <input
                                            value={
                                                data.tin_number
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                setData(
                                                    "tin_number",
                                                    event
                                                        .target
                                                        .value
                                                )
                                            }
                                            className={
                                                inputClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors.tin_number
                                            }
                                        />
                                    </div>

                                    <div>
                                        <label className={labelClass}>
                                            VAT/BIN Number
                                        </label>

                                        <input
                                            value={
                                                data.vat_number
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                setData(
                                                    "vat_number",
                                                    event
                                                        .target
                                                        .value
                                                )
                                            }
                                            className={
                                                inputClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors.vat_number
                                            }
                                        />
                                    </div>

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

                                    <div>
                                        <label className={labelClass}>
                                            Postal Code
                                        </label>

                                        <input
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
                                            className={
                                                inputClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors.postal_code
                                            }
                                        />
                                    </div>

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
                            </div>

                            {/* Documents */}
                            <div className="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                                <div className="mb-6 flex items-start gap-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-800">
                                        <FileCheck2 className="h-5 w-5" />
                                    </div>

                                    <div>
                                        <h2 className="font-semibold">
                                            Required Documents
                                        </h2>

                                        <p className="mt-1 text-sm text-neutral-500">
                                            Upload PDF, JPG,
                                            JPEG or PNG.
                                            Maximum 5 MB per
                                            file. You can
                                            preview, change or
                                            remove documents
                                            before submitting.
                                        </p>
                                    </div>
                                </div>

                                <div className="grid gap-5 md:grid-cols-2">
                                    <FileField
                                        label="Bank Cheque *"
                                        file={
                                            data.bank_check
                                        }
                                        onChange={fileHandler(
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
                                        onChange={fileHandler(
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
                                        onChange={fileHandler(
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
                                        onChange={fileHandler(
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
                                        label="VAT/BIN Document"
                                        file={
                                            data.vat_document
                                        }
                                        onChange={fileHandler(
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
                                                className="h-full bg-neutral-900 transition-all dark:bg-white"
                                                style={{
                                                    width: `${progress.percentage}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Submit */}
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-xs leading-5 text-neutral-500">
                                    By submitting this
                                    application, your
                                    information and private
                                    documents will be sent
                                    for administrative
                                    review.
                                </p>

                                <button
                                    type="submit"
                                    disabled={
                                        processing
                                    }
                                    className="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-lg bg-neutral-950 px-6 font-medium text-white transition hover:bg-neutral-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-neutral-950"
                                >
                                    <Send className="h-4 w-4" />

                                    {processing
                                        ? "Submitting..."
                                        : "Submit Depot Application"}
                                </button>
                            </div>
                        </form>

                        {/* Information Sidebar */}
                        <aside className="space-y-4">
                            <InfoCard
                                icon={
                                    ShieldCheck
                                }
                                title="Admin Approval"
                                text="Submitting this form does not automatically create an active Depot. Your application must first be reviewed and approved."
                            />

                            <InfoCard
                                icon={
                                    FileCheck2
                                }
                                title="Document Verification"
                                text="Your bank cheque, trade license and identity documents will be reviewed by authorized administrators."
                            />

                            <InfoCard
                                icon={
                                    MapPin
                                }
                                title="Distribution Area"
                                text="The selected district becomes the proposed operating area for the Depot."
                            />
                        </aside>
                    </div>
                </div>
            </section>
        </FrontendLayout>
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
                        PDF, JPG or PNG · Max
                        5 MB
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
                    file={
                        file
                    }
                    onChange={
                        onChange
                    }
                    onRemove={
                        onRemove
                    }
                />
            )}

            <ErrorText
                message={
                    error
                }
            />
        </div>
    );
}

function DocumentPreview({
    file,
    onChange,
    onRemove,
}: {
    file:
        File;

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
            {/* Document header */}
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
                            {
                                file.name
                            }
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

            {/* Fallback */}
            {!isImage &&
                !isPdf && (
                    <div className="flex min-h-36 flex-col items-center justify-center p-5 text-center">
                        <FileText className="mb-2 h-8 w-8 text-neutral-400" />

                        <p className="text-sm font-medium">
                            Preview unavailable
                        </p>

                        <p className="mt-1 text-xs text-neutral-500">
                            You can still
                            submit this
                            document.
                        </p>
                    </div>
                )}

            {/* Document actions */}
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

function InfoCard({
    icon: Icon,
    title,
    text,
}: {
    icon:
        typeof ShieldCheck;

    title:
        string;

    text:
        string;
}) {
    return (
        <div className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
            <Icon className="h-5 w-5" />

            <h3 className="mt-3 font-semibold">
                {title}
            </h3>

            <p className="mt-2 text-sm leading-6 text-neutral-500">
                {text}
            </p>
        </div>
    );
}

function formatFileSize(
    bytes:
        number
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
