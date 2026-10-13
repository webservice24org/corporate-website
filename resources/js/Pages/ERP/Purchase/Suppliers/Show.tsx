import { Head, Link, usePage } from "@inertiajs/react";
import ERPLayout from "@/Layouts/erp-layout";
import SupplierRecords from "@/Components/ERP/Purchase/SupplierRecords";
import { useAuthorization } from "@/hooks/useAuthorization";
import type { Supplier, SupplierAddress, SupplierContact } from "@/types/supplier";
import type { PageProps } from "@/types";

type Props = { supplier: Supplier; contacts: SupplierContact[]; addresses: SupplierAddress[] };
export default function Show({ supplier, contacts, addresses }: Props) {
    const { can } = useAuthorization();
    const { errors } = usePage<PageProps>().props;
    const values = [
        ["Company", supplier.company?.name], ["Code", supplier.code], ["Status", supplier.status],
        ["Currency", supplier.currency_code], ["Email", supplier.email], ["Phone", supplier.phone],
        ["Tax number", supplier.tax_number], ["Payment terms", `${supplier.payment_terms_days} days`],
    ];
    return (
        <ERPLayout>
            <Head title={supplier.name} />
            <div className="mx-auto max-w-6xl space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3"><div><Link href="/erp/purchase/suppliers" className="text-sm text-indigo-700">Back to suppliers</Link><h1 className="mt-2 text-2xl font-semibold">{supplier.name}</h1></div>{can("erp.suppliers.edit") && <Link href={`/erp/purchase/suppliers/${supplier.id}/edit`} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white">Edit supplier</Link>}</div>
                {errors.record && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{errors.record}</p>}
                <section className="rounded-xl border bg-white p-5"><dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">{values.map(([label, value]) => <div key={label}><dt className="text-xs font-medium uppercase text-slate-500">{label}</dt><dd className="mt-1 break-words text-sm">{value || "—"}</dd></div>)}</dl>{supplier.notes && <div className="mt-5 border-t pt-4"><h2 className="text-sm font-medium">Notes</h2><p className="mt-2 whitespace-pre-wrap break-words text-sm text-slate-600">{supplier.notes}</p></div>}</section>
                <SupplierRecords supplierId={supplier.id} kind="contacts" canManage={can("erp.suppliers.contacts.manage")} records={contacts.map((item) => ({ id: item.id, title: item.name, values: { name: item.name, job_title: item.job_title ?? "", email: item.email ?? "", phone: item.phone ?? "", is_primary: item.is_primary } }))} />
                <SupplierRecords supplierId={supplier.id} kind="addresses" canManage={can("erp.suppliers.addresses.manage")} records={addresses.map((item) => ({ id: item.id, title: item.label, values: { label: item.label, address_line_1: item.address_line_1, address_line_2: item.address_line_2 ?? "", city: item.city ?? "", state: item.state ?? "", postal_code: item.postal_code ?? "", country: item.country ?? "", is_default: item.is_default } }))} />
            </div>
        </ERPLayout>
    );
}
