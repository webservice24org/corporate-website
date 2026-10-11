import type {
    ReactNode,
} from "react";
import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Building2,
    Edit,
    MapPin,
    Trash2,
    User,
    UserRound,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import type {
    Seller,
} from "@/types/seller";

type Props = {
    seller:
        Seller & {
            proprietor_photo?: string | null;
        };
};

export default function Show({
    seller,
}: Props) {
    const {
        can,
    } =
        useAuthorization();

    const remove = () => {
        if (
            !window.confirm(
                "Delete this Seller?"
            )
        ) {
            return;
        }

        router.delete(
            `/erp/crm/sellers/${seller.id}`
        );
    };

    return (
        <ERPLayout>
            <Head
                title={
                    seller.business_name
                }
            />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <Link
                            href="/erp/crm/sellers"
                            className="mb-3 inline-flex items-center gap-1 text-sm text-neutral-500"
                        >
                            <ArrowLeft className="h-4 w-4" />

                            Back to Sellers
                        </Link>

                        <h1 className="text-2xl font-bold">
                            {
                                seller.business_name
                            }
                        </h1>

                        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-neutral-500">
                            <span>
                                {seller.seller_code ??
                                    `Seller #${seller.id}`}
                            </span>

                            <span>•</span>

                            <span className="capitalize">
                                {seller.seller_type.replace(
                                    "_",
                                    " "
                                )}
                            </span>

                            <span>•</span>

                            <span>
                                {seller.is_active
                                    ? "Active"
                                    : "Inactive"}
                            </span>
                        </div>
                    </div>

                    <div className="flex gap-2">
                        {can(
                            "erp.sellers.edit"
                        ) && (
                            <Link
                                href={`/erp/crm/sellers/${seller.id}/edit`}
                                className="inline-flex h-10 items-center gap-2 rounded-md border border-neutral-200 px-4 text-sm dark:border-neutral-700"
                            >
                                <Edit className="h-4 w-4" />

                                Edit
                            </Link>
                        )}

                        {can(
                            "erp.sellers.delete"
                        ) && (
                            <button
                                type="button"
                                onClick={
                                    remove
                                }
                                className="inline-flex h-10 items-center gap-2 rounded-md border border-red-200 px-4 text-sm text-red-600 dark:border-red-900"
                            >
                                <Trash2 className="h-4 w-4" />

                                Delete
                            </button>
                        )}
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card
                        title="Business Information"
                        icon={
                            Building2
                        }
                    >
                        <ProprietorPhoto
                            name={
                                seller.proprietor_name
                            }
                            photo={
                                seller.proprietor_photo
                            }
                            src={
                                seller.proprietor_photo
                                    ? `/erp/crm/sellers/${seller.id}/proprietor-photo`
                                    : null
                            }
                        />

                        <Info
                            label="Business Name"
                            value={
                                seller.business_name
                            }
                        />

                        <Info
                            label="Proprietor"
                            value={
                                seller.proprietor_name
                            }
                        />

                        <Info
                            label="Phone"
                            value={
                                seller.phone
                            }
                        />

                        <Info
                            label="Alternate Phone"
                            value={
                                seller.alternate_phone
                            }
                        />

                        <Info
                            label="Email"
                            value={
                                seller.email
                            }
                        />

                        <Info
                            label="Address"
                            value={
                                seller.business_address
                            }
                        />

                        <Info
                            label="Postal Code"
                            value={
                                seller.postal_code
                            }
                        />
                    </Card>

                    <Card
                        title="Distribution Hierarchy"
                        icon={
                            MapPin
                        }
                    >
                        <Info
                            label="Company"
                            value={
                                seller.company
                                    ?.name
                            }
                        />

                        <Info
                            label="Depot"
                            value={
                                seller.dealer
                                    ?.depot
                                    ?.name
                            }
                        />

                        <Info
                            label="Dealer"
                            value={
                                seller.dealer
                                    ?.business_name
                            }
                        />

                        <Info
                            label="Dealer Code"
                            value={
                                seller.dealer
                                    ?.dealer_code
                            }
                        />

                        <Info
                            label="Upazila"
                            value={
                                seller.dealer
                                    ?.upazila
                                    ?.name
                            }
                        />

                        <Info
                            label="District"
                            value={
                                seller.dealer
                                    ?.depot
                                    ?.district
                                    ?.name
                            }
                        />

                        <Info
                            label="Division"
                            value={
                                seller.dealer
                                    ?.depot
                                    ?.district
                                    ?.division
                                    ?.name
                            }
                        />
                    </Card>

                    <Card
                        title="Legal Information"
                        icon={
                            Building2
                        }
                    >
                        <Info
                            label="Trade License"
                            value={
                                seller.trade_license_number
                            }
                        />

                        <Info
                            label="NID"
                            value={
                                seller.nid_number
                            }
                        />

                        <Info
                            label="TIN"
                            value={
                                seller.tin_number
                            }
                        />

                        <Info
                            label="VAT / BIN"
                            value={
                                seller.vat_number
                            }
                        />
                    </Card>

                    <Card
                        title="Commercial Settings"
                        icon={
                            User
                        }
                    >
                        <Info
                            label="Credit Limit"
                            value={Number(
                                seller.credit_limit
                            ).toLocaleString()}
                        />

                        <Info
                            label="Payment Terms"
                            value={`${seller.payment_terms_days} days`}
                        />

                        <Info
                            label="Responsible User"
                            value={
                                seller.manager
                                    ?.name
                            }
                        />

                        <Info
                            label="Manager Email"
                            value={
                                seller.manager
                                    ?.email
                            }
                        />

                        <Info
                            label="Notes"
                            value={
                                seller.notes
                            }
                        />
                    </Card>
                </div>
            </div>
        </ERPLayout>
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
        <div className="rounded-xl border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                <div className="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    {photo && src ? (
                        <img
                            src={
                                src
                            }
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
                            href={
                                src
                            }
                            target="_blank"
                            rel="noreferrer"
                            className="mt-3 inline-flex h-9 items-center rounded-md border border-neutral-200 bg-white px-3 text-sm font-medium transition hover:bg-neutral-100 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                        >
                            View Full Picture
                        </a>
                    ) : (
                        <p className="mt-2 text-sm text-neutral-500">
                            No proprietor picture uploaded.
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}

function Card({
    title,
    icon: Icon,
    children,
}: {
    title:
        string;

    icon:
        typeof Building2;

    children:ReactNode;
}) {
    return (
        <section className="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
            <div className="mb-5 flex items-center gap-2">
                <Icon className="h-5 w-5" />

                <h2 className="font-semibold">
                    {title}
                </h2>
            </div>

            <div className="space-y-4">
                {children}
            </div>
        </section>
    );
}

function Info({
    label,
    value,
}: {
    label:
        string;

    value?:
        string | number | null;
}) {
    return (
        <div>
            <p className="text-xs font-medium uppercase tracking-wide text-neutral-400">
                {label}
            </p>

            <p className="mt-1 whitespace-pre-wrap text-sm">
                {value ??
                    "—"}
            </p>
        </div>
    );
}