import {
    Head,
    Link,
    router,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Building2,
    Check,
    CheckCircle2,
    Download,
    FileText,
    MapPin,
    ShieldCheck,
    Store,
    X,
    XCircle,
} from "lucide-react";

import {
    useMemo,
    useState,
} from "react";

import type {
    FormEvent,
    ReactNode,
} from "react";


import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import type {
    DealerApplication,
    DealerApplicationDocument,
} from "@/types/dealer";

type Props = {
    application:
        DealerApplication & {
            proprietor_photo?: string | null;
        };
};

const statusStyles: Record<
    DealerApplication["status"],
    string
> = {
    pending:
        "bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300",

    under_review:
        "bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300",

    approved:
        "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300",

    rejected:
        "bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300",
};

function statusLabel(
    value: string
) {
    return value
        .replaceAll("_", " ")
        .replace(/\b\w/g, (char) =>
            char.toUpperCase()
        );
}

function documentLabel(
    type: string
) {
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

        photo:
            "Photo",

        other:
            "Other Document",
    };

    return (
        labels[type] ??
        statusLabel(type)
    );
}

function formatFileSize(
    bytes: number | null
) {
    if (!bytes) {
        return "—";
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
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

export default function Show({
    application,
}: Props) {
    const {
        can,
    } =
        useAuthorization();

    const [
        approveOpen,
        setApproveOpen,
    ] =
        useState(false);

    const [
        rejectOpen,
        setRejectOpen,
    ] =
        useState(false);

    const approveForm =
        useForm({
            credit_limit:
                application.requested_credit_limit
                    ? String(
                          application.requested_credit_limit
                      )
                    : "0",

            payment_terms_days:
                "0",

            manager_user_id:
                "",

            admin_notes:
                application.admin_notes ??
                "",
        });

    const rejectForm =
        useForm({
            rejection_reason:
                "",

            admin_notes:
                application.admin_notes ??
                "",
        });

    const requiredDocuments =
        useMemo(
            () => [
                "bank_check",
                "trade_license",
                "nid",
            ],
            []
        );

    const requiredVerified =
        requiredDocuments.every(
            (type) => {
                const document =
                    application.documents?.find(
                        (item) =>
                            item.document_type ===
                            type
                    );

                return Boolean(
                    document?.verified_at
                );
            }
        );

    const markUnderReview =
        () => {
            router.post(
                `/erp/crm/dealer-applications/${application.id}/under-review`,
                {},
                {
                    preserveScroll:
                        true,
                }
            );
        };

    const verifyDocument = (
        document:
            DealerApplicationDocument
    ) => {
        router.post(
            `/erp/crm/dealer-applications/${application.id}/documents/${document.id}/verify`,
            {},
            {
                preserveScroll:
                    true,
            }
        );
    };

    const approve = (
        event:
            FormEvent
    ) => {
        event.preventDefault();

        approveForm.post(
            `/erp/crm/dealer-applications/${application.id}/approve`,
            {
                preserveScroll:
                    true,
            }
        );
    };

    const reject = (
        event:
            FormEvent
    ) => {
        event.preventDefault();

        rejectForm.post(
            `/erp/crm/dealer-applications/${application.id}/reject`,
            {
                preserveScroll:
                    true,

                onSuccess:
                    () =>
                        setRejectOpen(
                            false
                        ),
            }
        );
    };

    return (
        <ERPLayout>
            <Head
                title={`Dealer Application - ${application.business_name}`}
            />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Link
                            href="/erp/crm/dealer-applications"
                            className="mb-3 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-950 dark:hover:text-white"
                        >
                            <ArrowLeft className="h-4 w-4" />

                            Dealer Applications
                        </Link>

                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold">
                                {
                                    application.business_name
                                }
                            </h1>

                            <span
                                className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                    statusStyles[
                                        application
                                            .status
                                    ]
                                }`}
                            >
                                {statusLabel(
                                    application.status
                                )}
                            </span>
                        </div>

                        <p className="mt-1 text-sm text-neutral-500">
                            Dealer application
                            #{application.id}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {application.status ===
                            "pending" &&
                            can(
                                "erp.dealer-applications.review"
                            ) && (
                                <button
                                    type="button"
                                    onClick={
                                        markUnderReview
                                    }
                                    className="inline-flex h-10 items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-4 text-sm font-medium text-blue-700 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-300"
                                >
                                    <ShieldCheck className="h-4 w-4" />

                                    Start Review
                                </button>
                            )}

                        {(application.status ===
                            "pending" ||
                            application.status ===
                                "under_review") &&
                            can(
                                "erp.dealer-applications.approve"
                            ) && (
                                <button
                                    type="button"
                                    disabled={
                                        !requiredVerified
                                    }
                                    onClick={() =>
                                        setApproveOpen(
                                            true
                                        )
                                    }
                                    className="inline-flex h-10 items-center gap-2 rounded-md bg-emerald-600 px-4 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <CheckCircle2 className="h-4 w-4" />

                                    Approve
                                </button>
                            )}

                        {(application.status ===
                            "pending" ||
                            application.status ===
                                "under_review") &&
                            can(
                                "erp.dealer-applications.reject"
                            ) && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setRejectOpen(
                                            true
                                        )
                                    }
                                    className="inline-flex h-10 items-center gap-2 rounded-md bg-red-600 px-4 text-sm font-medium text-white"
                                >
                                    <XCircle className="h-4 w-4" />

                                    Reject
                                </button>
                            )}
                    </div>
                </div>

                {!requiredVerified &&
                    application.status !==
                        "approved" && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                            Bank Cheque, Trade
                            License, and NID must all
                            be verified before this
                            Dealer can be approved.
                        </div>
                    )}

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <div className="space-y-6">
                        <InfoCard
                            title="Business Information"
                            icon={
                                Building2
                            }
                        >
                            <ProprietorPhoto
                                name={
                                    application.proprietor_name
                                }
                                photo={
                                    application.proprietor_photo
                                }
                                src={
                                    application.proprietor_photo
                                        ? `/erp/crm/dealer-applications/${application.id}/proprietor-photo`
                                        : null
                                }
                            />

                            <InfoGrid>
                                <Info
                                    label="Business Name"
                                    value={
                                        application.business_name
                                    }
                                />

                                <Info
                                    label="Proprietor"
                                    value={
                                        application.proprietor_name
                                    }
                                />

                                <Info
                                    label="Phone"
                                    value={
                                        application.phone
                                    }
                                />

                                <Info
                                    label="Alternate Phone"
                                    value={
                                        application.alternate_phone
                                    }
                                />

                                <Info
                                    label="Email"
                                    value={
                                        application.email
                                    }
                                />

                                <Info
                                    label="Years in Business"
                                    value={
                                        application.years_in_business
                                    }
                                />

                                <Info
                                    label="Requested Credit Limit"
                                    value={
                                        application.requested_credit_limit
                                            ? Number(
                                                  application.requested_credit_limit
                                              ).toLocaleString()
                                            : "0"
                                    }
                                />

                                <Info
                                    label="Postal Code"
                                    value={
                                        application.postal_code
                                    }
                                />
                            </InfoGrid>

                            <div className="mt-5">
                                <Info
                                    label="Business Address"
                                    value={
                                        application.business_address
                                    }
                                />
                            </div>

                            {application.business_description && (
                                <div className="mt-5">
                                    <Info
                                        label="Business Description"
                                        value={
                                            application.business_description
                                        }
                                    />
                                </div>
                            )}
                        </InfoCard>

                        <InfoCard
                            title="Legal Information"
                            icon={
                                FileText
                            }
                        >
                            <InfoGrid>
                                <Info
                                    label="Trade License"
                                    value={
                                        application.trade_license_number
                                    }
                                />

                                <Info
                                    label="NID"
                                    value={
                                        application.nid_number
                                    }
                                />

                                <Info
                                    label="TIN"
                                    value={
                                        application.tin_number
                                    }
                                />

                                <Info
                                    label="VAT / BIN"
                                    value={
                                        application.vat_number
                                    }
                                />
                            </InfoGrid>
                        </InfoCard>

                        <InfoCard
                            title="Documents"
                            icon={
                                FileText
                            }
                        >
                            <div className="space-y-3">
                                {application.documents?.map(
                                    (
                                        document
                                    ) => (
                                        <div
                                            key={
                                                document.id
                                            }
                                            className="flex flex-col gap-3 rounded-lg border border-neutral-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800"
                                        >
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-medium">
                                                        {documentLabel(
                                                            document.document_type
                                                        )}
                                                    </p>

                                                    {document.verified_at ? (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                            <Check className="h-3 w-3" />

                                                            Verified
                                                        </span>
                                                    ) : (
                                                        <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                                            Unverified
                                                        </span>
                                                    )}
                                                </div>

                                                <p className="mt-1 text-xs text-neutral-500">
                                                    {
                                                        document.original_name
                                                    }{" "}
                                                    ·{" "}
                                                    {formatFileSize(
                                                        document.file_size
                                                    )}
                                                </p>
                                            </div>

                                            <div className="flex flex-wrap gap-2">
                                                {can(
                                                    "erp.dealer-applications.documents"
                                                ) && (
                                                    <a
                                                        href={`/erp/crm/dealer-applications/${application.id}/documents/${document.id}/download`}
                                                        className="inline-flex h-9 items-center gap-2 rounded-md border border-neutral-200 px-3 text-sm font-medium dark:border-neutral-800"
                                                    >
                                                        <Download className="h-4 w-4" />

                                                        Download
                                                    </a>
                                                )}

                                                {can(
                                                    "erp.dealer-applications.documents"
                                                ) &&
                                                    !document.verified_at && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                verifyDocument(
                                                                    document
                                                                )
                                                            }
                                                            className="inline-flex h-9 items-center gap-2 rounded-md bg-emerald-600 px-3 text-sm font-medium text-white"
                                                        >
                                                            <Check className="h-4 w-4" />

                                                            Verify
                                                        </button>
                                                    )}
                                            </div>
                                        </div>
                                    )
                                )}

                                {!application.documents
                                    ?.length && (
                                    <p className="py-6 text-center text-sm text-neutral-500">
                                        No documents
                                        found.
                                    </p>
                                )}
                            </div>
                        </InfoCard>
                    </div>

                    <div className="space-y-6">
                        <InfoCard
                            title="Distribution"
                            icon={
                                Store
                            }
                        >
                            <div className="space-y-4">
                                <Info
                                    label="Company"
                                    value={
                                        application.company
                                            ?.name
                                    }
                                />

                                <Info
                                    label="Depot"
                                    value={
                                        application.depot
                                            ?.name
                                    }
                                />

                                <Info
                                    label="District"
                                    value={
                                        application.depot
                                            ?.district
                                            ?.name
                                    }
                                />

                                <Info
                                    label="Division"
                                    value={
                                        application.depot
                                            ?.district
                                            ?.division
                                            ?.name
                                    }
                                />

                                <Info
                                    label="Upazila"
                                    value={
                                        application.upazila
                                            ?.name
                                    }
                                />
                            </div>
                        </InfoCard>

                        <InfoCard
                            title="Application"
                            icon={
                                MapPin
                            }
                        >
                            <div className="space-y-4">
                                <Info
                                    label="Submitted"
                                    value={new Date(
                                        application.created_at
                                    ).toLocaleString()}
                                />

                                <Info
                                    label="Reviewed"
                                    value={
                                        application.reviewed_at
                                            ? new Date(
                                                  application.reviewed_at
                                              ).toLocaleString()
                                            : null
                                    }
                                />

                                <Info
                                    label="Approved"
                                    value={
                                        application.approved_at
                                            ? new Date(
                                                  application.approved_at
                                              ).toLocaleString()
                                            : null
                                    }
                                />

                                <Info
                                    label="Rejected"
                                    value={
                                        application.rejected_at
                                            ? new Date(
                                                  application.rejected_at
                                              ).toLocaleString()
                                            : null
                                    }
                                />
                            </div>
                        </InfoCard>

                        {application.status ===
                            "approved" &&
                            application.dealer && (
                                <Link
                                    href={`/erp/crm/dealers/${application.dealer.id}`}
                                    className="flex items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300"
                                >
                                    View Approved Dealer

                                    <Store className="h-4 w-4" />
                                </Link>
                            )}

                        {application.rejection_reason && (
                            <div className="rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-900 dark:bg-red-950/30">
                                <p className="text-sm font-semibold text-red-700 dark:text-red-300">
                                    Rejection Reason
                                </p>

                                <p className="mt-2 text-sm leading-6 text-red-700/80 dark:text-red-300/80">
                                    {
                                        application.rejection_reason
                                    }
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {approveOpen && (
                <Modal
                    title="Approve Dealer Application"
                    onClose={() =>
                        setApproveOpen(
                            false
                        )
                    }
                >
                    <form
                        onSubmit={
                            approve
                        }
                        className="space-y-4"
                    >
                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Credit Limit
                            </label>

                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={
                                    approveForm.data
                                        .credit_limit
                                }
                                onChange={(
                                    event
                                ) =>
                                    approveForm.setData(
                                        "credit_limit",
                                        event.target.value
                                    )
                                }
                                className="h-10 w-full rounded-md border border-neutral-200 bg-white px-3 text-sm dark:border-neutral-800 dark:bg-neutral-950"
                            />
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Payment Terms Days
                            </label>

                            <input
                                type="number"
                                min="0"
                                value={
                                    approveForm.data
                                        .payment_terms_days
                                }
                                onChange={(
                                    event
                                ) =>
                                    approveForm.setData(
                                        "payment_terms_days",
                                        event.target.value
                                    )
                                }
                                className="h-10 w-full rounded-md border border-neutral-200 bg-white px-3 text-sm dark:border-neutral-800 dark:bg-neutral-950"
                            />
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Admin Notes
                            </label>

                            <textarea
                                value={
                                    approveForm.data
                                        .admin_notes
                                }
                                onChange={(
                                    event
                                ) =>
                                    approveForm.setData(
                                        "admin_notes",
                                        event.target.value
                                    )
                                }
                                className="min-h-24 w-full rounded-md border border-neutral-200 bg-white p-3 text-sm dark:border-neutral-800 dark:bg-neutral-950"
                            />
                        </div>

                        

                        <div className="flex justify-end gap-2 pt-2">
                            <button
                                type="button"
                                onClick={() =>
                                    setApproveOpen(
                                        false
                                    )
                                }
                                className="h-10 rounded-md border border-neutral-200 px-4 text-sm font-medium dark:border-neutral-800"
                            >
                                Cancel
                            </button>

                            <button
                                type="submit"
                                disabled={
                                    approveForm.processing
                                }
                                className="h-10 rounded-md bg-emerald-600 px-4 text-sm font-medium text-white disabled:opacity-50"
                            >
                                Approve Dealer
                            </button>
                        </div>
                    </form>
                </Modal>
            )}

            {rejectOpen && (
                <Modal
                    title="Reject Dealer Application"
                    onClose={() =>
                        setRejectOpen(
                            false
                        )
                    }
                >
                    <form
                        onSubmit={
                            reject
                        }
                        className="space-y-4"
                    >
                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Rejection Reason *
                            </label>

                            <textarea
                                value={
                                    rejectForm.data
                                        .rejection_reason
                                }
                                onChange={(
                                    event
                                ) =>
                                    rejectForm.setData(
                                        "rejection_reason",
                                        event.target.value
                                    )
                                }
                                className="min-h-32 w-full rounded-md border border-neutral-200 bg-white p-3 text-sm dark:border-neutral-800 dark:bg-neutral-950"
                            />

                            {rejectForm.errors
                                .rejection_reason && (
                                <p className="mt-1 text-xs text-red-600">
                                    {
                                        rejectForm.errors
                                            .rejection_reason
                                    }
                                </p>
                            )}
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Admin Notes
                            </label>

                            <textarea
                                value={
                                    rejectForm.data
                                        .admin_notes
                                }
                                onChange={(
                                    event
                                ) =>
                                    rejectForm.setData(
                                        "admin_notes",
                                        event.target.value
                                    )
                                }
                                className="min-h-24 w-full rounded-md border border-neutral-200 bg-white p-3 text-sm dark:border-neutral-800 dark:bg-neutral-950"
                            />
                        </div>

                        <div className="flex justify-end gap-2 pt-2">
                            <button
                                type="button"
                                onClick={() =>
                                    setRejectOpen(
                                        false
                                    )
                                }
                                className="h-10 rounded-md border border-neutral-200 px-4 text-sm font-medium dark:border-neutral-800"
                            >
                                Cancel
                            </button>

                            <button
                                type="submit"
                                disabled={
                                    rejectForm.processing
                                }
                                className="h-10 rounded-md bg-red-600 px-4 text-sm font-medium text-white disabled:opacity-50"
                            >
                                Reject Application
                            </button>
                        </div>
                    </form>
                </Modal>
            )}
        </ERPLayout>
    );
}

function InfoCard({
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
        <div className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-950">
            <div className="mb-5 flex items-center gap-2">
                <Icon className="h-5 w-5" />

                <h2 className="font-semibold">
                    {title}
                </h2>
            </div>

            {children}
        </div>
    );
}

function ProprietorPhoto({
    name,
    photo,
    src,
}: {
    name: string;
    photo?: string | null;
    src?: string | null;
}) {
    return (
        <div className="mb-6 flex flex-col gap-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4 sm:flex-row sm:items-center dark:border-neutral-800 dark:bg-neutral-900/60">
            <div className="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-950">
                {photo && src ? (
                    <img
                        src={src}
                        alt={`${name} proprietor`}
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-sm font-medium text-neutral-400">
                        No Photo
                    </div>
                )}
            </div>

            <div className="min-w-0">
                <p className="text-xs font-medium uppercase tracking-wide text-neutral-400">
                    Proprietor Picture
                </p>

                <p className="mt-1 text-base font-semibold">
                    {name}
                </p>

                {photo && src ? (
                    <a
                        href={src}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-3 inline-flex h-9 items-center rounded-md border border-neutral-200 bg-white px-3 text-sm font-medium transition hover:bg-neutral-100 dark:border-neutral-800 dark:bg-neutral-950 dark:hover:bg-neutral-900"
                    >
                        View Full Picture
                    </a>
                ) : (
                    <p className="mt-2 text-sm text-neutral-500">
                        No proprietor picture was uploaded with this application.
                    </p>
                )}
            </div>
        </div>
    );
}

function InfoGrid({
    children,
}: {
    children:
        ReactNode;
}) {
    return (
        <div className="grid gap-5 md:grid-cols-2">
            {children}
        </div>
    );
}

function Info({
    label,
    value,
}: {
    label: string;

    value:
        | string
        | number
        | null
        | undefined;
}) {
    return (
        <div>
            <p className="text-xs font-medium uppercase tracking-wide text-neutral-400">
                {label}
            </p>

            <p className="mt-1 break-words text-sm">
                {value === null ||
                value === undefined ||
                value === ""
                    ? "—"
                    : value}
            </p>
        </div>
    );
}

function Modal({
    title,
    children,
    onClose,
}: {
    title: string;

    children:
        ReactNode;

    onClose:
        () => void;
}) {
    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4">
            <div className="w-full max-w-lg rounded-xl bg-white shadow-2xl dark:bg-neutral-950">
                <div className="flex items-center justify-between border-b border-neutral-200 p-5 dark:border-neutral-800">
                    <h2 className="font-semibold">
                        {title}
                    </h2>

                    <button
                        type="button"
                        onClick={
                            onClose
                        }
                        className="flex h-8 w-8 items-center justify-center rounded-md hover:bg-neutral-100 dark:hover:bg-neutral-900"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div className="p-5">
                    {children}
                </div>
            </div>
        </div>
    );
}