import type { ReactNode } from "react";
import { Head, Link } from "@inertiajs/react";
import { ArrowLeft, Pencil } from "lucide-react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import type { Customer } from "@/types/customer";

export default function Show({ customer }: { customer: Customer }) {
    const { can } = useAuthorization();

    return (
        <ERPLayout>
            <Head title={`Customer - ${customer.name}`} />

            <div className="space-y-6">
                {/* Header */}
                <div>
                    <Link
                        href="/erp/crm/customers"
                        className="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-200"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Customers
                    </Link>

                    <div className="mt-3 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-2xl font-semibold">
                                {customer.business_name || customer.name}
                            </h1>
                            <p className="mt-1 text-sm text-neutral-500">
                                {customer.customer_code ?? `Customer #${customer.id}`} ·{" "}
                                <span className="capitalize">{customer.customer_type}</span>
                            </p>
                        </div>

                        {can("erp.customers.edit") && (
                            <Link
                                href={`/erp/crm/customers/${customer.id}/edit`}
                                className="inline-flex h-10 items-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white transition-colors hover:bg-neutral-800 dark:bg-white dark:text-neutral-950 dark:hover:bg-neutral-200"
                            >
                                <Pencil className="h-4 w-4" />
                                Edit Customer
                            </Link>
                        )}
                    </div>
                </div>

                {/* Cards Grid */}
                <div className="grid gap-6 xl:grid-cols-2">
                    <Card title="Customer Information">
                        <Info label="Name" value={customer.name} />
                        <Info label="Business" value={customer.business_name} />
                        <Info label="Contact Person" value={customer.contact_person} />
                        <Info label="Company" value={customer.company?.name} />
                        <Info
                            label="Status"
                            value={customer.is_active ? "Active" : "Inactive"}
                        />
                    </Card>

                    <Card title="Contact">
                        <Info label="Phone" value={customer.phone} />
                        <Info label="Alternate Phone" value={customer.alternate_phone} />
                        <Info label="Email" value={customer.email} />
                    </Card>

                    <Card title="Legal & Commercial">
                        <Info label="NID" value={customer.nid_number} />
                        <Info label="TIN" value={customer.tin_number} />
                        <Info label="VAT / BIN" value={customer.vat_number} />
                        <Info
                            label="Credit Limit"
                            value={Number(customer.credit_limit).toLocaleString()}
                        />
                        <Info
                            label="Payment Terms"
                            value={`${customer.payment_terms_days} days`}
                        />
                    </Card>

                    <Card title="Contacts">
                        {customer.contacts?.length ? (
                            <div className="col-span-full">
                                {customer.contacts.map((x, i) => (
                                    <div
                                        key={i}
                                        className="border-b py-3 last:border-0 dark:border-neutral-800"
                                    >
                                        <div className="font-medium">
                                            {x.name}
                                            {x.is_primary && (
                                                <span className="ml-2 text-xs text-emerald-600 dark:text-emerald-400">
                                                    Primary
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-sm text-neutral-500">
                                            {[x.designation, x.phone, x.email]
                                                .filter(Boolean)
                                                .join(" · ")}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <Empty />
                        )}
                    </Card>

                    <Card title="Addresses">
                        {customer.addresses?.length ? (
                            <div className="col-span-full">
                                {customer.addresses.map((x, i) => (
                                    <div
                                        key={i}
                                        className="border-b py-3 last:border-0 dark:border-neutral-800"
                                    >
                                        <div className="font-medium capitalize">
                                            {x.label || x.address_type}
                                            {x.is_primary && (
                                                <span className="ml-2 text-xs text-emerald-600 dark:text-emerald-400">
                                                    Primary
                                                </span>
                                            )}
                                        </div>
                                        <div className="mt-1 text-sm text-neutral-500">
                                            {x.address}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <Empty />
                        )}
                    </Card>

                    <Card title="Notes">
                        <div className="col-span-full">
                            <p className="whitespace-pre-wrap text-sm text-neutral-600 dark:text-neutral-300">
                                {customer.notes || "—"}
                            </p>
                        </div>
                    </Card>
                </div>
            </div>
        </ERPLayout>
    );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border bg-white p-6 dark:border-neutral-800 dark:bg-neutral-950">
            <h2 className="mb-4 font-semibold text-neutral-900 dark:text-neutral-100">
                {title}
            </h2>
            <div className="grid gap-4 md:grid-cols-2">{children}</div>
        </section>
    );
}

function Info({
    label,
    value,
}: {
    label: string;
    value: string | number | null | undefined;
}) {
    return (
        <div>
            <div className="text-xs font-medium uppercase tracking-wide text-neutral-400">
                {label}
            </div>
            <div className="mt-1 text-sm text-neutral-900 dark:text-neutral-100">
                {value === null || value === undefined || value === "" ? "—" : value}
            </div>
        </div>
    );
}

function Empty() {
    return <p className="col-span-full text-sm text-neutral-500">None added.</p>;
}