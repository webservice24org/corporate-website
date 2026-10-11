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
    FileText,
    MapPin,
    ShieldCheck,
    UserRound,
    X,
} from "lucide-react";

import {
    useState,
} from "react";

import type {
    FormEvent,
    ReactNode,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

import type {
    DepotApplication,
} from "@/types/depot";

type Props = {
    application:
        DepotApplication & {
            proprietor_photo?: string | null;
        };
};

const statusStyles: Record<
    string,
    string
> = {
    pending:
        "bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300",

    under_review:
        "bg-blue-100 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300",

    approved:
        "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300",

    rejected:
        "bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300",
};

function statusLabel(
    value: string
) {
    return value
        .replaceAll("_", " ")
        .replace(
            /\b\w/g,
            (character) =>
                character.toUpperCase()
        );
}

function money(
    value:
        | string
        | number
        | null
) {
    if (
        value === null ||
        value === ""
    ) {
        return "—";
    }

    return new Intl.NumberFormat(
        "en-BD",
        {
            minimumFractionDigits:
                2,

            maximumFractionDigits:
                2,
        }
    ).format(
        Number(value)
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
            "Other",
    };

    return (
        labels[type] ??
        type
    );
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

    const markUnderReview =
        () => {
            router.post(
                `/erp/crm/depot-applications/${application.id}/under-review`,
                {},
                {
                    preserveScroll:
                        true,
                }
            );
        };

    const verifyDocument =
        (
            documentId:
                number
        ) => {
            router.post(
                `/erp/crm/depot-applications/${application.id}/documents/${documentId}/verify`,
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
                title={`Depot Application - ${application.business_name}`}
            />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Link
                            href="/erp/crm/depot-applications"
                            className="mb-4 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-950 dark:hover:text-white"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Depot Applications
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
                                        application.status
                                    ] ??
                                    ""
                                }`}
                            >
                                {statusLabel(
                                    application.status
                                )}
                            </span>
                        </div>

                        <p className="mt-1 text-sm text-neutral-500">
                            Application #
                            {
                                application.id
                            }{" "}
                            • Submitted{" "}
                            {new Date(
                                application.created_at
                            ).toLocaleString()}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {application.status ===
                            "pending" &&
                            can(
                                "erp.depot-applications.review"
                            ) && (
                                <button
                                    type="button"
                                    onClick={
                                        markUnderReview
                                    }
                                    className="h-10 rounded-md border border-neutral-200 px-4 text-sm font-medium dark:border-neutral-800"
                                >
                                    Mark Under Review
                                </button>
                            )}

                        {application.status !==
                            "approved" &&
                            application.status !==
                                "rejected" &&
                            can(
                                "erp.depot-applications.approve"
                            ) && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setApproveOpen(
                                            true
                                        )
                                    }
                                    className="inline-flex h-10 items-center gap-2 rounded-md bg-emerald-600 px-4 text-sm font-medium text-white"
                                >
                                    <Check className="h-4 w-4" />

                                    Approve
                                </button>
                            )}

                        {application.status !==
                            "approved" &&
                            can(
                                "erp.depot-applications.reject"
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
                                    <X className="h-4 w-4" />

                                    Reject
                                </button>
                            )}

                        {application.depot && (
                            <Link
                                href={`/erp/crm/depots/${application.depot.id}`}
                                className="inline-flex h-10 items-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white dark:bg-white dark:text-neutral-950"
                            >
                                <Building2 className="h-4 w-4" />
                                View Depot
                            </Link>
                        )}
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
                    <div className="space-y-6">
                        <Card
                            title="Applicant Information"
                            icon={
                                UserRound
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
                                        ? `/erp/crm/depot-applications/${application.id}/proprietor-photo`
                                        : null
                                }
                            />

                            <div className="grid gap-5 sm:grid-cols-2">
                                <Field
                                    label="Business Name"
                                    value={
                                        application.business_name
                                    }
                                />

                                <Field
                                    label="Proprietor"
                                    value={
                                        application.proprietor_name
                                    }
                                />

                                <Field
                                    label="Phone"
                                    value={
                                        application.phone
                                    }
                                />

                                <Field
                                    label="Alternate Phone"
                                    value={
                                        application.alternate_phone
                                    }
                                />

                                <Field
                                    label="Email"
                                    value={
                                        application.email
                                    }
                                />

                                <Field
                                    label="Years in Business"
                                    value={
                                        application.years_in_business
                                    }
                                />

                                <Field
                                    label="Trade License"
                                    value={
                                        application.trade_license_number
                                    }
                                />

                                <Field
                                    label="NID"
                                    value={
                                        application.nid_number
                                    }
                                />

                                <Field
                                    label="TIN"
                                    value={
                                        application.tin_number
                                    }
                                />

                                <Field
                                    label="VAT / BIN"
                                    value={
                                        application.vat_number
                                    }
                                />

                                <Field
                                    label="Requested Credit Limit"
                                    value={
                                        money(
                                            application.requested_credit_limit
                                        )
                                    }
                                />

                                <Field
                                    label="Postal Code"
                                    value={
                                        application.postal_code
                                    }
                                />
                            </div>
                        </Card>

                        <Card
                            title="Proposed Distribution Area"
                            icon={
                                MapPin
                            }
                        >
                            <div className="grid gap-5 sm:grid-cols-3">
                                <Field
                                    label="Company"
                                    value={
                                        application
                                            .company
                                            ?.name
                                    }
                                />

                                <Field
                                    label="Division"
                                    value={
                                        application
                                            .division
                                            ?.name
                                    }
                                />

                                <Field
                                    label="District"
                                    value={
                                        application
                                            .district
                                            ?.name
                                    }
                                />
                            </div>

                            <div className="mt-5">
                                <Field
                                    label="Business Address"
                                    value={
                                        application.business_address
                                    }
                                />
                            </div>
                        </Card>

                        <Card
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
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-medium">
                                                        {documentLabel(
                                                            document.document_type
                                                        )}
                                                    </p>

                                                    {document.verified_at && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                            <BadgeCheck className="h-3.5 w-3.5" />
                                                            Verified
                                                        </span>
                                                    )}
                                                </div>

                                                <p className="mt-1 truncate text-xs text-neutral-500">
                                                    {
                                                        document.original_name
                                                    }
                                                </p>

                                                {document.file_size && (
                                                    <p className="mt-1 text-xs text-neutral-400">
                                                        {(
                                                            document.file_size /
                                                            1024 /
                                                            1024
                                                        ).toFixed(
                                                            2
                                                        )}{" "}
                                                        MB
                                                    </p>
                                                )}
                                            </div>

                                            <div className="flex gap-2">
                                                {can(
                                                    "erp.depot-applications.documents"
                                                ) && (
                                                    <a
                                                        href={`/erp/crm/depot-applications/${application.id}/documents/${document.id}/download`}
                                                        className="inline-flex h-9 items-center gap-2 rounded-md border border-neutral-200 px-3 text-xs font-medium dark:border-neutral-800"
                                                    >
                                                        <Download className="h-4 w-4" />

                                                        Download
                                                    </a>
                                                )}

                                                {can(
                                                    "erp.depot-applications.documents"
                                                ) &&
                                                    !document.verified_at && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                verifyDocument(
                                                                    document.id
                                                                )
                                                            }
                                                            className="inline-flex h-9 items-center gap-2 rounded-md bg-neutral-950 px-3 text-xs font-medium text-white dark:bg-white dark:text-neutral-950"
                                                        >
                                                            <ShieldCheck className="h-4 w-4" />

                                                            Verify
                                                        </button>
                                                    )}
                                            </div>
                                        </div>
                                    )
                                )}

                                {!application.documents?.length && (
                                    <p className="py-8 text-center text-sm text-neutral-500">
                                        No documents uploaded.
                                    </p>
                                )}
                            </div>
                        </Card>

                        {application.business_description && (
                            <Card
                                title="Business Description"
                                icon={
                                    Building2
                                }
                            >
                                <p className="whitespace-pre-wrap text-sm leading-7 text-neutral-600 dark:text-neutral-400">
                                    {
                                        application.business_description
                                    }
                                </p>
                            </Card>
                        )}
                    </div>

                    <div className="space-y-6">
                        <Card
                            title="Review Status"
                            icon={
                                ShieldCheck
                            }
                        >
                            <div className="space-y-4">
                                <Field
                                    label="Status"
                                    value={statusLabel(
                                        application.status
                                    )}
                                />

                                <Field
                                    label="Reviewed At"
                                    value={
                                        application.reviewed_at
                                            ? new Date(
                                                  application.reviewed_at
                                              ).toLocaleString()
                                            : null
                                    }
                                />

                                <Field
                                    label="Reviewed By"
                                    value={
                                        application
                                            .reviewed_by
                                            ?.name
                                    }
                                />

                                <Field
                                    label="Approved At"
                                    value={
                                        application.approved_at
                                            ? new Date(
                                                  application.approved_at
                                              ).toLocaleString()
                                            : null
                                    }
                                />

                                <Field
                                    label="Approved By"
                                    value={
                                        application
                                            .approved_by
                                            ?.name
                                    }
                                />

                                <Field
                                    label="Rejected At"
                                    value={
                                        application.rejected_at
                                            ? new Date(
                                                  application.rejected_at
                                              ).toLocaleString()
                                            : null
                                    }
                                />

                                <Field
                                    label="Rejected By"
                                    value={
                                        application
                                            .rejected_by
                                            ?.name
                                    }
                                />
                            </div>
                        </Card>

                        {application.rejection_reason && (
                            <div className="rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-900 dark:bg-red-950/20">
                                <h3 className="font-semibold text-red-800 dark:text-red-300">
                                    Rejection Reason
                                </h3>

                                <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-red-700 dark:text-red-400">
                                    {
                                        application.rejection_reason
                                    }
                                </p>
                            </div>
                        )}

                        {application.admin_notes && (
                            <Card
                                title="Admin Notes"
                                icon={
                                    FileText
                                }
                            >
                                <p className="whitespace-pre-wrap text-sm leading-7 text-neutral-600 dark:text-neutral-400">
                                    {
                                        application.admin_notes
                                    }
                                </p>
                            </Card>
                        )}
                    </div>
                </div>
            </div>

            {approveOpen && (
                <ApproveModal
                    application={
                        application
                    }
                    onClose={() =>
                        setApproveOpen(
                            false
                        )
                    }
                />
            )}

            {rejectOpen && (
                <RejectModal
                    application={
                        application
                    }
                    onClose={() =>
                        setRejectOpen(
                            false
                        )
                    }
                />
            )}
        </ERPLayout>
    );
}

function ApproveModal({
    application,
    onClose,
}: {
    application:
        DepotApplication;

    onClose: () => void;
}) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm({
        credit_limit:
            application.requested_credit_limit ??
            "",

        payment_terms_days:
            0,

        manager_user_id:
            "",

        admin_notes:
            application.admin_notes ??
            "",
    });

    const submit = (
        event: FormEvent
    ) => {
        event.preventDefault();

        post(
            `/erp/crm/depot-applications/${application.id}/approve`,
            {
                preserveScroll:
                    true,
            }
        );
    };

    return (
        <ModalShell
            title="Approve Depot Application"
            onClose={
                onClose
            }
        >
            <form
                onSubmit={
                    submit
                }
                className="space-y-5"
            >
                <div className="rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300">
                    Approval will
                    create an active
                    Depot from this
                    application.
                </div>

                <div>
                    <label className="mb-1.5 block text-sm font-medium">
                        Approved Credit Limit
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
                            )
                        }
                        className="h-10 w-full rounded-md border border-neutral-200 bg-white px-3 text-sm dark:border-neutral-800 dark:bg-neutral-950"
                    />

                    {errors.credit_limit && (
                        <p className="mt-1 text-xs text-red-600">
                            {
                                errors.credit_limit
                            }
                        </p>
                    )}
                </div>

                <div>
                    <label className="mb-1.5 block text-sm font-medium">
                        Payment Terms Days
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
                                Number(
                                    event
                                        .target
                                        .value
                                )
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
                            data.admin_notes
                        }
                        onChange={(
                            event
                        ) =>
                            setData(
                                "admin_notes",
                                event
                                    .target
                                    .value
                            )
                        }
                        className="min-h-24 w-full rounded-md border border-neutral-200 bg-white p-3 text-sm dark:border-neutral-800 dark:bg-neutral-950"
                    />
                </div>

                <div className="flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={
                            onClose
                        }
                        className="h-10 rounded-md border border-neutral-200 px-4 text-sm dark:border-neutral-800"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        disabled={
                            processing
                        }
                        className="h-10 rounded-md bg-emerald-600 px-4 text-sm font-medium text-white disabled:opacity-50"
                    >
                        {processing
                            ? "Approving..."
                            : "Approve & Create Depot"}
                    </button>
                </div>
            </form>
        </ModalShell>
    );
}

function RejectModal({
    application,
    onClose,
}: {
    application:
        DepotApplication;

    onClose: () => void;
}) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm({
        rejection_reason:
            "",

        admin_notes:
            "",
    });

    const submit = (
        event: FormEvent
    ) => {
        event.preventDefault();

        post(
            `/erp/crm/depot-applications/${application.id}/reject`,
            {
                preserveScroll:
                    true,

                onSuccess:
                    onClose,
            }
        );
    };

    return (
        <ModalShell
            title="Reject Depot Application"
            onClose={
                onClose
            }
        >
            <form
                onSubmit={
                    submit
                }
                className="space-y-5"
            >
                <div>
                    <label className="mb-1.5 block text-sm font-medium">
                        Rejection Reason *
                    </label>

                    <textarea
                        value={
                            data.rejection_reason
                        }
                        onChange={(
                            event
                        ) =>
                            setData(
                                "rejection_reason",
                                event
                                    .target
                                    .value
                            )
                        }
                        className="min-h-28 w-full rounded-md border border-neutral-200 bg-white p-3 text-sm dark:border-neutral-800 dark:bg-neutral-950"
                    />

                    {errors.rejection_reason && (
                        <p className="mt-1 text-xs text-red-600">
                            {
                                errors.rejection_reason
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
                            data.admin_notes
                        }
                        onChange={(
                            event
                        ) =>
                            setData(
                                "admin_notes",
                                event
                                    .target
                                    .value
                            )
                        }
                        className="min-h-24 w-full rounded-md border border-neutral-200 bg-white p-3 text-sm dark:border-neutral-800 dark:bg-neutral-950"
                    />
                </div>

                <div className="flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={
                            onClose
                        }
                        className="h-10 rounded-md border border-neutral-200 px-4 text-sm dark:border-neutral-800"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        disabled={
                            processing
                        }
                        className="h-10 rounded-md bg-red-600 px-4 text-sm font-medium text-white disabled:opacity-50"
                    >
                        {processing
                            ? "Rejecting..."
                            : "Reject Application"}
                    </button>
                </div>
            </form>
        </ModalShell>
    );
}

function ModalShell({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: ReactNode;
}) {
    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4">
            <div className="w-full max-w-lg rounded-xl bg-white shadow-2xl dark:bg-neutral-950">
                <div className="flex items-center justify-between border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    <h2 className="font-semibold">
                        {title}
                    </h2>

                    <button
                        type="button"
                        onClick={
                            onClose
                        }
                        className="rounded-md p-2 hover:bg-neutral-100 dark:hover:bg-neutral-900"
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
                    <UserRound className="h-10 w-10 text-neutral-400" />
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

function Card({
    title,
    icon: Icon,
    children,
}: {
    title: string;
    icon:
        typeof Building2;
    children: ReactNode;
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

function Field({
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

            <p className="mt-1 whitespace-pre-wrap text-sm text-neutral-800 dark:text-neutral-200">
                {value === null ||
                value === undefined ||
                value === ""
                    ? "—"
                    : value}
            </p>
        </div>
    );
}