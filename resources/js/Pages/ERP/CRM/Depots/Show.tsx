import {
    Head,
    Link,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Building2,
    FileText,
    History,
    Landmark,
    Mail,
    MapPin,
    Pencil,
    Phone,
    UserRound,
    Wallet,
} from "lucide-react";

import type {
    ReactNode,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

import type {
    Depot,
} from "@/types/depot";

type Props = {
    depot:
        Depot;
};

export default function Show({
    depot,
}: Props) {
    const { can } = useAuthorization();

    return (
        <ERPLayout>
            <Head
                title={
                    depot.name
                }
            />

            <div className="space-y-6">
                <div>
                    <Link
                        href="/erp/crm/depots"
                        className="mb-4 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-950 dark:hover:text-white"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Depots
                    </Link>

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex items-start gap-4">
                            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-neutral-100 dark:bg-neutral-900">
                                <Building2 className="h-6 w-6" />
                            </div>

                            <div>
                                <div className="flex flex-wrap items-center gap-3">
                                    <h1 className="text-2xl font-semibold">
                                        {
                                            depot.name
                                        }
                                    </h1>

                                    <span
                                        className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                            depot.is_active
                                                ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                                                : "bg-neutral-100 text-neutral-600 dark:bg-neutral-800"
                                        }`}
                                    >
                                        {depot.is_active
                                            ? "Active"
                                            : "Inactive"}
                                    </span>
                                </div>

                                <p className="mt-1 text-sm text-neutral-500">
                                    {depot.depot_code ??
                                        `Depot #${depot.id}`}
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            {can(
                                "erp.depots.activity.view"
                            ) && (
                                <Link
                                    href={`/erp/crm/depots/${depot.id}/activity`}
                                    className="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-md border border-neutral-200 bg-white px-4 text-sm font-medium transition hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-950 dark:hover:bg-neutral-900"
                                >
                                    <History className="h-4 w-4" />
                                    Activity History
                                </Link>
                            )}

                            {can(
                                "erp.depot-documents.view"
                            ) && (
                                <Link
                                    href={`/erp/crm/depots/${depot.id}/documents`}
                                    className="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-md border border-neutral-200 bg-white px-4 text-sm font-medium transition hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-950 dark:hover:bg-neutral-900"
                                >
                                    <FileText className="h-4 w-4" />
                                    Manage Documents
                                </Link>
                            )}

                            {can(
                                "erp.depots.edit"
                            ) && (
                                <Link
                                    href={`/erp/crm/depots/${depot.id}/edit`}
                                    className="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white transition hover:bg-neutral-800 dark:bg-white dark:text-neutral-950 dark:hover:bg-neutral-200"
                                >
                                    <Pencil className="h-4 w-4" />
                                    Edit Depot
                                </Link>
                            )}
                        </div>
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
                    <div className="space-y-6">
                        <Card
                            title="Depot Information"
                            icon={
                                Building2
                            }
                        >
                            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                <Field
                                    label="Company"
                                    value={
                                        depot.company
                                            ?.name
                                    }
                                />

                                <Field
                                    label="Division"
                                    value={
                                        depot.division
                                            ?.name
                                    }
                                />

                                <Field
                                    label="District"
                                    value={
                                        depot.district
                                            ?.name
                                    }
                                />

                                <Field
                                    label="Proprietor"
                                    value={
                                        depot.proprietor_name
                                    }
                                />

                                <Field
                                    label="Manager"
                                    value={
                                        depot.manager
                                            ?.name
                                    }
                                />

                                <Field
                                    label="Postal Code"
                                    value={
                                        depot.postal_code
                                    }
                                />
                            </div>

                            <div className="mt-5">
                                <Field
                                    label="Address"
                                    value={
                                        depot.address
                                    }
                                />
                            </div>
                        </Card>

                        <Card
                            title="Business Documents"
                            icon={
                                FileText
                            }
                        >
                            <div className="grid gap-5 sm:grid-cols-2">
                                <Field
                                    label="Trade License"
                                    value={
                                        depot.trade_license_number
                                    }
                                />

                                <Field
                                    label="NID"
                                    value={
                                        depot.nid_number
                                    }
                                />

                                <Field
                                    label="TIN"
                                    value={
                                        depot.tin_number
                                    }
                                />

                                <Field
                                    label="VAT / BIN"
                                    value={
                                        depot.vat_number
                                    }
                                />
                            </div>
                        </Card>

                        {depot.applications &&
                            depot.applications.length >
                                0 && (
                                <Card
                                    title="Registration History"
                                    icon={
                                        Landmark
                                    }
                                >
                                    <div className="space-y-3">
                                        {depot.applications.map(
                                            (
                                                application
                                            ) => (
                                                <Link
                                                    key={
                                                        application.id
                                                    }
                                                    href={`/erp/crm/depot-applications/${application.id}`}
                                                    className="block rounded-lg border border-neutral-200 p-4 transition hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900"
                                                >
                                                    <div className="flex items-center justify-between gap-4">
                                                        <div>
                                                            <p className="font-medium">
                                                                Application #
                                                                {
                                                                    application.id
                                                                }
                                                            </p>

                                                            <p className="mt-1 text-xs text-neutral-500">
                                                                Submitted{" "}
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
                                </Card>
                            )}

                        {depot.notes && (
                            <Card
                                title="Notes"
                                icon={
                                    FileText
                                }
                            >
                                <p className="whitespace-pre-wrap text-sm leading-7 text-neutral-600 dark:text-neutral-400">
                                    {
                                        depot.notes
                                    }
                                </p>
                            </Card>
                        )}
                    </div>

                    <div className="space-y-6">
                        <Card
                            title="Contact"
                            icon={
                                UserRound
                            }
                        >
                            <div className="space-y-4">
                                <ContactRow
                                    icon={
                                        Phone
                                    }
                                    value={
                                        depot.phone
                                    }
                                />

                                <ContactRow
                                    icon={
                                        Phone
                                    }
                                    value={
                                        depot.alternate_phone
                                    }
                                />

                                <ContactRow
                                    icon={
                                        Mail
                                    }
                                    value={
                                        depot.email
                                    }
                                />

                                <ContactRow
                                    icon={
                                        MapPin
                                    }
                                    value={
                                        depot.district
                                            ?.name
                                    }
                                />
                            </div>
                        </Card>

                        <Card
                            title="Commercial"
                            icon={
                                Wallet
                            }
                        >
                            <div className="space-y-5">
                                <Field
                                    label="Credit Limit"
                                    value={new Intl.NumberFormat(
                                        "en-BD",
                                        {
                                            minimumFractionDigits:
                                                2,
                                        }
                                    ).format(
                                        Number(
                                            depot.credit_limit
                                        )
                                    )}
                                />

                                <Field
                                    label="Payment Terms"
                                    value={`${depot.payment_terms_days} day(s)`}
                                />
                            </div>
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

            <p className="mt-1 whitespace-pre-wrap text-sm">
                {value === null ||
                value === undefined ||
                value === ""
                    ? "—"
                    : value}
            </p>
        </div>
    );
}

function ContactRow({
    icon: Icon,
    value,
}: {
    icon:
        typeof Phone;
    value:
        string
        | null
        | undefined;
}) {
    return (
        <div className="flex items-start gap-3">
            <Icon className="mt-0.5 h-4 w-4 shrink-0 text-neutral-400" />

            <span className="text-sm">
                {value || "—"}
            </span>
        </div>
    );
}