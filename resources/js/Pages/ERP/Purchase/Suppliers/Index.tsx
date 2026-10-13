import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";
import { useAuthorization } from "@/hooks/useAuthorization";
import type { Supplier, SupplierCompany } from "@/types/supplier";
import type { PageProps } from "@/types";

type Props = {
    suppliers: { data: Supplier[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
    companies: SupplierCompany[];
    filters: { search?: string; company_id?: number | string; status?: string };
};
export default function Index({ suppliers, companies, filters }: Props) {
    const { can } = useAuthorization();
    const { errors } = usePage<PageProps>().props;
    const [search, setSearch] = useState(filters.search ?? "");
    const [company, setCompany] = useState(String(filters.company_id ?? ""));
    const [status, setStatus] = useState(filters.status ?? "");
    const [deleting, setDeleting] = useState<Supplier | null>(null);
    const [processing, setProcessing] = useState(false);
    const apply = () => router.get("/erp/purchase/suppliers", { search, company_id: company || undefined, status: status || undefined }, { preserveState: true, replace: true });
    const remove = () => {
        if (!deleting) return;
        setProcessing(true);
        router.delete(`/erp/purchase/suppliers/${deleting.id}`, { preserveScroll: true, onSuccess: () => setDeleting(null), onError: () => setDeleting(null), onFinish: () => setProcessing(false) });
    };
    return (
        <ERPLayout>
            <Head title="Suppliers" />
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div><h1 className="text-2xl font-semibold">Suppliers</h1><p className="mt-1 text-sm text-slate-500">Manage supplier profiles, contacts and addresses.</p></div>
                    {can("erp.suppliers.create") && <Link href="/erp/purchase/suppliers/create" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white">Add supplier</Link>}
                </div>
                {errors.record && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{errors.record}</p>}
                <form onSubmit={(e) => { e.preventDefault(); apply(); }} className="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <label className="text-sm">Search<input value={search} maxLength={200} onChange={(e) => setSearch(e.target.value)} placeholder="Name, code, email or phone" className="mt-1 w-full rounded-lg border p-2" /></label>
                    <label className="text-sm">Company<select value={company} onChange={(e) => setCompany(e.target.value)} className="mt-1 w-full rounded-lg border p-2"><option value="">All authorized companies</option>{companies.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                    <label className="text-sm">Status<select value={status} onChange={(e) => setStatus(e.target.value)} className="mt-1 w-full rounded-lg border p-2"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                    <button type="submit" className="self-end rounded-lg border px-4 py-2 text-sm">Apply filters</button>
                </form>
                <div className="overflow-x-auto rounded-xl border bg-white">
                    <table className="w-full min-w-175 text-left text-sm">
                        <thead className="border-b bg-slate-50"><tr>{["Supplier", "Company", "Contact", "Terms", "Status", "Actions"].map((label) => <th key={label} className="p-4">{label}</th>)}</tr></thead>
                        <tbody>{suppliers.data.map((item) => <tr key={item.id} className="border-b last:border-0">
                            <td className="p-4"><Link href={`/erp/purchase/suppliers/${item.id}`} className="font-medium text-indigo-700">{item.name}</Link><p className="text-xs text-slate-500">{item.code}</p></td>
                            <td className="p-4">{item.company?.name}</td><td className="p-4"><p>{item.email ?? "—"}</p><p className="text-slate-500">{item.phone ?? "—"}</p></td><td className="p-4">{item.payment_terms_days} days · {item.currency_code}</td><td className="p-4"><span className={item.status === "active" ? "text-emerald-700" : "text-slate-500"}>{item.status}</span></td>
                            <td className="p-4"><div className="flex gap-3">{can("erp.suppliers.edit") && <Link className="text-indigo-700" href={`/erp/purchase/suppliers/${item.id}/edit`}>Edit</Link>}{can("erp.suppliers.delete") && <button type="button" className="text-red-700" onClick={() => setDeleting(item)}>Delete</button>}</div></td>
                        </tr>)}{suppliers.data.length === 0 && <tr><td colSpan={6} className="p-8 text-center text-slate-500">No suppliers found. Adjust the filters or add a supplier.</td></tr>}</tbody>
                    </table>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3 text-sm"><p>{suppliers.total} suppliers · Page {suppliers.current_page} of {suppliers.last_page}</p><div className="flex gap-4">{suppliers.prev_page_url && <Link href={suppliers.prev_page_url}>Previous</Link>}{suppliers.next_page_url && <Link href={suppliers.next_page_url}>Next</Link>}</div></div>
            </div>
            <DeleteConfirmDialog open={deleting !== null} onOpenChange={(open) => { if (!open && !processing) setDeleting(null); }} onConfirm={remove} itemName={deleting?.name} description="Delete this supplier and its contacts and addresses? Suppliers used by purchasing documents cannot be deleted." processing={processing} />
        </ERPLayout>
    );
}
