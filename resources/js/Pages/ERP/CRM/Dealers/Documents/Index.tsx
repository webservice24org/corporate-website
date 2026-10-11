import {
    Head,
    Link,
    router,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    BadgeCheck,
    Building2,
    Check,
    Download,
    Eye,
    FileClock,
    FilePlus2,
    FileText,
    History,
    ShieldCheck,
    Trash2,
    Upload,
    X,
} from "lucide-react";

import {
    useMemo,
    useState,
} from "react";

import type {
    FormEvent,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

type UserSummary = {
    id: number;
    name: string;
};

type DealerSummary = {
    id: number;
    business_name: string;
    dealer_code?: string | null;

    company?: {
        id: number;
        name: string;
    } | null;

    depot?: {
        id: number;
        name: string;
        depot_code?: string | null;

        district?: {
            id: number;
            name: string;

            division?: {
                id: number;
                name: string;
            } | null;
        } | null;
    } | null;

    upazila?: {
        id: number;
        name: string;
    } | null;
};

type DealerDocument = {
    id: number;
    dealer_id: number;

    document_type: string;
    title?: string | null;

    original_name?: string | null;
    mime_type?: string | null;
    file_size?: number | null;

    version: number;
    is_current: boolean;

    verified_at?: string | null;
    verified_by?: number | null;

    uploaded_by?: number | null;

    notes?: string | null;
    created_at: string;

    verifier?: UserSummary | null;
    uploader?: UserSummary | null;
};

type Props = {
    dealer: DealerSummary;
    documents: DealerDocument[];
    documentTypes: string[];
};

type UploadFormData = {
    document_type: string;
    title: string;
    file: File | null;
    notes: string;
};

const labels: Record<
    string,
    string
> = {
    bank_check:
        "Bank Cheque",

    trade_license:
        "Trade License",

    nid:
        "NID / ID Card",

    tin:
        "TIN Document",

    vat:
        "VAT / BIN Document",

    bank_statement:
        "Bank Statement",

    other:
        "Other Document",
};

function documentLabel(
    type: string
) {
    return (
        labels[type] ??
        type.replaceAll(
            "_",
            " "
        )
    );
}

function formatSize(
    bytes:
        | number
        | null
        | undefined
) {
    if (!bytes) {
        return "—";
    }

    if (
        bytes <
        1024
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

export default function Index({
    dealer,
    documents,
    documentTypes,
}: Props) {
    const {
        can,
    } =
        useAuthorization();

    const [
        uploadOpen,
        setUploadOpen,
    ] =
        useState(false);

    const grouped =
        useMemo(() => {
            return documentTypes.map(
                (type) => ({
                    type,

                    versions:
                        documents.filter(
                            (document) =>
                                document.document_type ===
                                type
                        ),
                })
            );
        }, [
            documentTypes,
            documents,
        ]);

    const verify = (
        document:
            DealerDocument
    ) => {
        router.post(
            `/erp/crm/dealers/${dealer.id}/documents/${document.id}/verify`,
            {},
            {
                preserveScroll:
                    true,
            }
        );
    };

    const unverify = (
        document:
            DealerDocument
    ) => {
        router.post(
            `/erp/crm/dealers/${dealer.id}/documents/${document.id}/unverify`,
            {},
            {
                preserveScroll:
                    true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head
                title={`Dealer Documents - ${dealer.business_name}`}
            />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Link
                            href={`/erp/crm/dealers/${dealer.id}`}
                            className="mb-3 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-950 dark:hover:text-white"
                        >
                            <ArrowLeft className="h-4 w-4" />

                            Back to Dealer
                        </Link>

                        <div className="flex items-center gap-3">
                            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-neutral-100 dark:bg-neutral-900">
                                <FileText className="h-5 w-5" />
                            </div>

                            <div>
                                <h1 className="text-2xl font-semibold">
                                    Dealer Documents
                                </h1>

                                <p className="mt-1 text-sm text-neutral-500">
                                    {dealer.business_name}

                                    {dealer.dealer_code
                                        ? ` · ${dealer.dealer_code}`
                                        : ""}

                                    {dealer.depot?.depot_code
                                        ? ` · ${dealer.depot.depot_code}`
                                        : ""}
                                </p>
                            </div>
                        </div>
                    </div>

                    {can(
                        "erp.dealer-documents.create"
                    ) && (
                        <button
                            type="button"
                            onClick={() =>
                                setUploadOpen(
                                    true
                                )
                            }
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white dark:bg-white dark:text-neutral-950"
                        >
                            <FilePlus2 className="h-4 w-4" />

                            Upload / Replace Document
                        </button>
                    )}
                </div>

                <div className="grid gap-4 md:grid-cols-4">
                    <SummaryCard
                        icon={
                            Building2
                        }
                        label="Company"
                        value={
                            dealer.company
                                ?.name ??
                            "—"
                        }
                    />

                    <SummaryCard
                        icon={
                            Building2
                        }
                        label="Depot"
                        value={
                            dealer.depot
                                ?.name ??
                            "—"
                        }
                    />

                    <SummaryCard
                        icon={
                            Building2
                        }
                        label="District"
                        value={
                            dealer.depot
                                ?.district
                                ?.name ??
                            "—"
                        }
                    />

                    <SummaryCard
                        icon={
                            Building2
                        }
                        label="Upazila"
                        value={
                            dealer.upazila
                                ?.name ??
                            "—"
                        }
                    />
                </div>

                <div className="space-y-5">
                    {grouped.map(
                        ({
                            type,
                            versions,
                        }) => (
                            <DocumentGroup
                                key={
                                    type
                                }
                                dealerId={
                                    dealer.id
                                }
                                type={
                                    type
                                }
                                versions={
                                    versions
                                }
                                canVerify={
                                    can(
                                        "erp.dealer-documents.verify"
                                    )
                                }
                                canDelete={
                                    can(
                                        "erp.dealer-documents.delete"
                                    )
                                }
                                onVerify={
                                    verify
                                }
                                onUnverify={
                                    unverify
                                }
                            />
                        )
                    )}
                </div>
            </div>

            {uploadOpen && (
                <UploadModal
                    dealerId={
                        dealer.id
                    }
                    documentTypes={
                        documentTypes
                    }
                    onClose={() =>
                        setUploadOpen(
                            false
                        )
                    }
                />
            )}
        </ERPLayout>
    );
}

function DocumentGroup({
    dealerId,
    type,
    versions,
    canVerify,
    canDelete,
    onVerify,
    onUnverify,
}: {
    dealerId: number;
    type: string;
    versions:
        DealerDocument[];
    canVerify: boolean;
    canDelete: boolean;

    onVerify: (
        document:
            DealerDocument
    ) => void;

    onUnverify: (
        document:
            DealerDocument
    ) => void;
}) {
    const current =
        versions.find(
            (document) =>
                document.is_current
        );

    return (
        <section className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-950">
            <div className="flex flex-col gap-3 border-b border-neutral-200 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-semibold">
                            {documentLabel(
                                type
                            )}
                        </h2>

                        {current?.verified_at ? (
                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                <BadgeCheck className="h-3.5 w-3.5" />

                                Current Verified
                            </span>
                        ) : current ? (
                            <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                Verification Required
                            </span>
                        ) : (
                            <span className="rounded-full bg-neutral-100 px-2 py-1 text-xs text-neutral-500 dark:bg-neutral-900">
                                Not Uploaded
                            </span>
                        )}
                    </div>

                    <p className="mt-1 text-xs text-neutral-500">
                        Replacements create a new version. Older versions remain in history.
                    </p>
                </div>

                <div className="inline-flex items-center gap-2 text-xs text-neutral-500">
                    <History className="h-4 w-4" />

                    {versions.length} version
                    {versions.length ===
                    1
                        ? ""
                        : "s"}
                </div>
            </div>

            {versions.length >
            0 ? (
                <div className="divide-y divide-neutral-200 dark:divide-neutral-800">
                    {versions.map(
                        (
                            document
                        ) => (
                            <div
                                key={
                                    document.id
                                }
                                className={`p-5 ${
                                    document.is_current
                                        ? "bg-neutral-50/80 dark:bg-neutral-900/40"
                                        : ""
                                }`}
                            >
                                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-medium">
                                                Version{" "}
                                                {
                                                    document.version
                                                }
                                            </p>

                                            {document.is_current && (
                                                <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                                                    Current
                                                </span>
                                            )}

                                            {document.verified_at ? (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                    <Check className="h-3 w-3" />

                                                    Verified
                                                </span>
                                            ) : (
                                                <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                                    Unverified
                                                </span>
                                            )}
                                        </div>

                                        <p className="mt-2 break-all text-sm">
                                            {document.title ||
                                                document.original_name ||
                                                documentLabel(
                                                    type
                                                )}
                                        </p>

                                        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-500">
                                            <span>
                                                {formatSize(
                                                    document.file_size
                                                )}
                                            </span>

                                            <span>
                                                Uploaded{" "}
                                                {new Date(
                                                    document.created_at
                                                ).toLocaleString()}
                                            </span>

                                            {document.uploader
                                                ?.name && (
                                                <span>
                                                    by{" "}
                                                    {
                                                        document
                                                            .uploader
                                                            .name
                                                    }
                                                </span>
                                            )}
                                        </div>

                                        {document.verified_at && (
                                            <p className="mt-2 text-xs text-emerald-600 dark:text-emerald-400">
                                                Verified{" "}
                                                {new Date(
                                                    document.verified_at
                                                ).toLocaleString()}
                                                {document.verifier
                                                    ?.name
                                                    ? ` by ${document.verifier.name}`
                                                    : ""}
                                            </p>
                                        )}

                                        {document.notes && (
                                            <p className="mt-3 whitespace-pre-wrap text-sm text-neutral-600 dark:text-neutral-400">
                                                {
                                                    document.notes
                                                }
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex shrink-0 flex-wrap gap-2">
                                        <a
                                            href={`/erp/crm/dealers/${dealerId}/documents/${document.id}/preview`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex h-9 items-center gap-2 rounded-md border border-neutral-200 px-3 text-sm dark:border-neutral-700"
                                        >
                                            <Eye className="h-4 w-4" />

                                            Preview
                                        </a>

                                        <a
                                            href={`/erp/crm/dealers/${dealerId}/documents/${document.id}/download`}
                                            className="inline-flex h-9 items-center gap-2 rounded-md border border-neutral-200 px-3 text-sm dark:border-neutral-700"
                                        >
                                            <Download className="h-4 w-4" />

                                            Download
                                        </a>

                                        {canVerify &&
                                            (document.verified_at ? (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        onUnverify(
                                                            document
                                                        )
                                                    }
                                                    className="inline-flex h-9 items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 text-sm font-medium text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300"
                                                >
                                                    <X className="h-4 w-4" />

                                                    Unverify
                                                </button>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        onVerify(
                                                            document
                                                        )
                                                    }
                                                    className="inline-flex h-9 items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300"
                                                >
                                                    <ShieldCheck className="h-4 w-4" />

                                                    Verify
                                                </button>
                                            ))}

                                        {canDelete &&
                                            document.is_current && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        if (
                                                            !window.confirm(
                                                                "Remove this current Dealer document? The file and version history will be preserved."
                                                            )
                                                        ) {
                                                            return;
                                                        }

                                                        router.delete(
                                                            `/erp/crm/dealers/${dealerId}/documents/${document.id}`,
                                                            {
                                                                preserveScroll:
                                                                    true,
                                                            }
                                                        );
                                                    }}
                                                    className="inline-flex h-9 items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 text-sm font-medium text-red-600 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"
                                                >
                                                    <Trash2 className="h-4 w-4" />

                                                    Remove
                                                </button>
                                            )}
                                    </div>
                                </div>
                            </div>
                        )
                    )}
                </div>
            ) : (
                <div className="flex items-center gap-3 p-5 text-sm text-neutral-500">
                    <FileClock className="h-5 w-5" />

                    No approved-master document has been uploaded for this type yet.
                </div>
            )}
        </section>
    );
}

function UploadModal({
    dealerId,
    documentTypes,
    onClose,
}: {
    dealerId: number;
    documentTypes:
        string[];
    onClose: () => void;
}) {
    const form =
        useForm<UploadFormData>(
            {
                document_type:
                    "",

                title:
                    "",

                file:
                    null,

                notes:
                    "",
            }
        );

    const submit = (
        event:
            FormEvent
    ) => {
        event.preventDefault();

        form.post(
            `/erp/crm/dealers/${dealerId}/documents`,
            {
                preserveScroll:
                    true,

                forceFormData:
                    true,

                onSuccess:
                    () => {
                        form.reset();
                        onClose();
                    },
            }
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div className="w-full max-w-xl rounded-xl bg-white p-6 shadow-2xl dark:bg-neutral-950">
                <div className="mb-5">
                    <h2 className="text-lg font-semibold">
                        Upload / Replace Dealer Document
                    </h2>

                    <p className="mt-1 text-sm text-neutral-500">
                        If this document type already exists, the current file is preserved as history and this upload becomes the next version.
                    </p>
                </div>

                <form
                    onSubmit={
                        submit
                    }
                    className="space-y-5"
                >
                    <div>
                        <label className="mb-1.5 block text-sm font-medium">
                            Document Type *
                        </label>

                        <select
                            value={
                                form.data.document_type
                            }
                            onChange={(
                                event
                            ) =>
                                form.setData(
                                    "document_type",
                                    event.target.value
                                )
                            }
                            className="h-10 w-full rounded-md border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option value="">
                                Select document type
                            </option>

                            {documentTypes.map(
                                (
                                    type
                                ) => (
                                    <option
                                        key={
                                            type
                                        }
                                        value={
                                            type
                                        }
                                    >
                                        {documentLabel(
                                            type
                                        )}
                                    </option>
                                )
                            )}
                        </select>

                        {form.errors.document_type && (
                            <p className="mt-1 text-xs text-red-600">
                                {
                                    form
                                        .errors
                                        .document_type
                                }
                            </p>
                        )}
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium">
                            Display Title
                        </label>

                        <input
                            value={
                                form.data.title
                            }
                            onChange={(
                                event
                            ) =>
                                form.setData(
                                    "title",
                                    event.target.value
                                )
                            }
                            className="h-10 w-full rounded-md border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-950"
                        />

                        {form.errors.title && (
                            <p className="mt-1 text-xs text-red-600">
                                {
                                    form
                                        .errors
                                        .title
                                }
                            </p>
                        )}
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium">
                            File *
                        </label>

                        <label className="flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-neutral-300 p-6 text-center dark:border-neutral-700">
                            <Upload className="h-6 w-6 text-neutral-400" />

                            <span className="mt-2 text-sm font-medium">
                                {form.data.file
                                    ? form.data.file.name
                                    : "Choose PDF or image"}
                            </span>

                            <span className="mt-1 text-xs text-neutral-500">
                                PDF, JPG, JPEG, PNG or WEBP. Maximum 5 MB.
                            </span>

                            <input
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                                className="hidden"
                                onChange={(
                                    event
                                ) => {
                                    form.setData(
                                        "file",
                                        event.target.files?.[0] ??
                                            null
                                    );

                                    event.target.value =
                                        "";
                                }}
                            />
                        </label>

                        {form.errors.file && (
                            <p className="mt-1 text-xs text-red-600">
                                {
                                    form
                                        .errors
                                        .file
                                }
                            </p>
                        )}
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium">
                            Notes
                        </label>

                        <textarea
                            value={
                                form.data.notes
                            }
                            onChange={(
                                event
                            ) =>
                                form.setData(
                                    "notes",
                                    event.target.value
                                )
                            }
                            className="min-h-24 w-full rounded-md border border-neutral-300 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-950"
                        />

                        {form.errors.notes && (
                            <p className="mt-1 text-xs text-red-600">
                                {
                                    form
                                        .errors
                                        .notes
                                }
                            </p>
                        )}
                    </div>

                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={
                                onClose
                            }
                            className="h-10 rounded-md border border-neutral-200 px-4 text-sm dark:border-neutral-700"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            disabled={
                                form.processing
                            }
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-neutral-950"
                        >
                            <Upload className="h-4 w-4" />

                            {form.processing
                                ? "Uploading..."
                                : "Upload Document"}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function SummaryCard({
    icon: Icon,
    label,
    value,
}: {
    icon:
        typeof Building2;
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
            <div className="flex items-center gap-3">
                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-900">
                    <Icon className="h-4 w-4" />
                </div>

                <div>
                    <p className="text-xs uppercase tracking-wide text-neutral-400">
                        {label}
                    </p>

                    <p className="mt-0.5 text-sm font-medium">
                        {value}
                    </p>
                </div>
            </div>
        </div>
    );
}
