import {
    Head,
    Link,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Building2,
    CreditCard,
    FileText,
    FolderOpen,
    History,
    MapPin,
    Pencil,
    Store,
    UserRound,
} from "lucide-react";

import type {
    ReactNode,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

import type {
    Dealer,
} from "@/types/dealer";

type Props = {
    dealer:
        Dealer;
};

export default function Show({
    dealer,
}: Props) {
    const { can } =
        useAuthorization();

    const canEdit =
        can("erp.dealers.edit");

    const canManageDocuments =
        can("erp.dealer-documents.view");

    const canViewActivity =
        can("erp.dealers.activity.view");

    return (
        <ERPLayout>
            <Head
                title={`Dealer - ${dealer.business_name}`}
            />

            <div className="space-y-6">
                <div>
                    <Link
                        href="/erp/crm/dealers"
                        className="mb-3 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-950 dark:hover:text-white"
                    >
                        <ArrowLeft className="h-4 w-4" />

                        Dealers
                    </Link>

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div className="flex flex-wrap items-center gap-3">
                                <h1 className="text-2xl font-semibold">
                                    {
                                        dealer.business_name
                                    }
                                </h1>

                                <span
                                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                        dealer.is_active
                                            ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                                            : "bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                    }`}
                                >
                                    {dealer.is_active
                                        ? "Active"
                                        : "Inactive"}
                                </span>
                            </div>

                            <p className="mt-1 text-sm text-neutral-500">
                                {dealer.dealer_code ??
                                    `Dealer #${dealer.id}`}
                            </p>
                        </div>

                        {(canEdit ||
                            canManageDocuments ||
                            canViewActivity) && (
                            <div className="flex flex-wrap gap-2">
                                {canViewActivity && (
                                    <Link
                                        href={`/erp/crm/dealers/${dealer.id}/activity`}
                                        className="inline-flex h-10 items-center gap-2 rounded-md border border-neutral-200 bg-white px-4 text-sm font-medium transition hover:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-950 dark:hover:bg-neutral-900"
                                    >
                                        <History className="h-4 w-4" />

                                        Activity History
                                    </Link>
                                )}

                                {canManageDocuments && (
                                    <Link
                                        href={`/erp/crm/dealers/${dealer.id}/documents`}
                                        className="inline-flex h-10 items-center gap-2 rounded-md border border-neutral-200 bg-white px-4 text-sm font-medium transition hover:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-950 dark:hover:bg-neutral-900"
                                    >
                                        <FolderOpen className="h-4 w-4" />

                                        Manage Documents
                                    </Link>
                                )}

                                {canEdit && (
                                    <Link
                                        href={`/erp/crm/dealers/${dealer.id}/edit`}
                                        className="inline-flex h-10 items-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white transition hover:bg-neutral-800 dark:bg-white dark:text-neutral-950 dark:hover:bg-neutral-200"
                                    >
                                        <Pencil className="h-4 w-4" />

                                        Edit Dealer
                                    </Link>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <div className="space-y-6">
                        <Card
                            title="Business Information"
                            icon={
                                Building2
                            }
                        >
                            <Grid>
                                <Info
                                    label="Business Name"
                                    value={
                                        dealer.business_name
                                    }
                                />

                                <Info
                                    label="Proprietor"
                                    value={
                                        dealer.proprietor_name
                                    }
                                />

                                <Info
                                    label="Phone"
                                    value={
                                        dealer.phone
                                    }
                                />

                                <Info
                                    label="Alternate Phone"
                                    value={
                                        dealer.alternate_phone
                                    }
                                />

                                <Info
                                    label="Email"
                                    value={
                                        dealer.email
                                    }
                                />

                                <Info
                                    label="Postal Code"
                                    value={
                                        dealer.postal_code
                                    }
                                />
                            </Grid>

                            <div className="mt-5">
                                <Info
                                    label="Address"
                                    value={
                                        dealer.address
                                    }
                                />
                            </div>
                        </Card>

                        <Card
                            title="Legal Information"
                            icon={
                                FileText
                            }
                        >
                            <Grid>
                                <Info
                                    label="Trade License"
                                    value={
                                        dealer.trade_license_number
                                    }
                                />

                                <Info
                                    label="NID"
                                    value={
                                        dealer.nid_number
                                    }
                                />

                                <Info
                                    label="TIN"
                                    value={
                                        dealer.tin_number
                                    }
                                />

                                <Info
                                    label="VAT / BIN"
                                    value={
                                        dealer.vat_number
                                    }
                                />
                            </Grid>
                        </Card>

                        <Card
                            title="Commercial Terms"
                            icon={
                                CreditCard
                            }
                        >
                            <Grid>
                                <Info
                                    label="Credit Limit"
                                    value={Number(
                                        dealer.credit_limit
                                    ).toLocaleString()}
                                />

                                <Info
                                    label="Payment Terms"
                                    value={`${dealer.payment_terms_days} days`}
                                />
                            </Grid>
                        </Card>

                        {dealer.notes && (
                            <Card
                                title="Notes"
                                icon={
                                    FileText
                                }
                            >
                                <p className="whitespace-pre-wrap text-sm leading-6 text-neutral-600 dark:text-neutral-300">
                                    {
                                        dealer.notes
                                    }
                                </p>
                            </Card>
                        )}
                    </div>

                    <div className="space-y-6">
                        <Card
                            title="Proprietor"
                            icon={
                                UserRound
                            }
                        >
                            <div className="flex flex-col items-center text-center">
                                <div className="flex h-32 w-32 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-neutral-100 dark:border-neutral-800 dark:bg-neutral-900">
                                    {dealer.proprietor_photo ? (
                                        <img
                                            src={`/erp/crm/dealers/${dealer.id}/proprietor-photo`}
                                            alt={
                                                dealer.proprietor_name ??
                                                "Dealer proprietor"
                                            }
                                            className="h-full w-full object-cover"
                                        />
                                    ) : (
                                        <UserRound className="h-12 w-12 text-neutral-400" />
                                    )}
                                </div>

                                <p className="mt-4 font-medium">
                                    {
                                        dealer.proprietor_name
                                    }
                                </p>

                                <p className="mt-1 text-sm text-neutral-500">
                                    Proprietor
                                </p>
                            </div>
                        </Card>

                        <Card
                            title="Distribution"
                            icon={
                                Store
                            }
                        >
                            <div className="space-y-4">
                                <Info
                                    label="Company"
                                    value={
                                        dealer.company
                                            ?.name
                                    }
                                />

                                <Info
                                    label="Depot"
                                    value={
                                        dealer.depot
                                            ?.name
                                    }
                                />

                                <Info
                                    label="District"
                                    value={
                                        dealer.depot
                                            ?.district
                                            ?.name
                                    }
                                />

                                <Info
                                    label="Division"
                                    value={
                                        dealer.depot
                                            ?.district
                                            ?.division
                                            ?.name
                                    }
                                />

                                <Info
                                    label="Upazila"
                                    value={
                                        dealer.upazila
                                            ?.name
                                    }
                                />
                            </div>
                        </Card>

                        <Card
                            title="Management"
                            icon={
                                UserRound
                            }
                        >
                            <Info
                                label="Manager"
                                value={
                                    dealer.manager?.name ??
                                    "Not Assigned"
                                }
                            />
                        </Card>

                        <Card
                            title="Applications"
                            icon={
                                MapPin
                            }
                        >
                            {dealer.applications
                                ?.length ? (
                                <div className="space-y-3">
                                    {dealer.applications.map(
                                        (
                                            application
                                        ) => (
                                            <Link
                                                key={
                                                    application.id
                                                }
                                                href={`/erp/crm/dealer-applications/${application.id}`}
                                                className="block rounded-lg border border-neutral-200 p-3 transition hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900"
                                            >
                                                <div className="flex items-center justify-between gap-3">
                                                    <div>
                                                        <p className="text-sm font-medium">
                                                            Application #
                                                            {
                                                                application.id
                                                            }
                                                        </p>

                                                        <p className="mt-1 text-xs text-neutral-500">
                                                            {new Date(
                                                                application.created_at
                                                            ).toLocaleDateString()}
                                                        </p>
                                                    </div>

                                                    <span className="text-xs capitalize text-neutral-500">
                                                        {application.status.replaceAll(
                                                            "_",
                                                            " "
                                                        )}
                                                    </span>
                                                </div>
                                            </Link>
                                        )
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-neutral-500">
                                    No application history.
                                </p>
                            )}
                        </Card>
                    </div>
                </div>
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

function Grid({
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