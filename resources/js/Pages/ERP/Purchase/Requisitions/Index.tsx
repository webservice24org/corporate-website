import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import type { Requisition, RequisitionCompany } from "@/types/purchase-requisition";

type Props = {
    requisitions: { data: Requisition[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
    companies: RequisitionCompany[];
    filters: { search?: string; status?: string; company_id?: string | number };
};
export default function Index({ requisitions, companies, filters }: Props) {
    const { can } = useAuthorization();
    const [search, setSearch] = useState(filters.search ?? "");
    const [status, setStatus] = useState(filters.status ?? "");
    const [company, setCompany] = useState(String(filters.company_id ?? ""));
    return (
        <ERPLayout>
            <Head title="Purchase requisitions" />
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-semibold">Purchase requisitions</h1><p className="mt-1 text-sm text-slate-500">Request purchases and track review decisions.</p></div>{can("erp.purchase-requisitions.create") && <Link href="/erp/purchase/requisitions/create" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white">New requisition</Link>}</div>
                <form onSubmit={(e) => { e.preventDefault(); router.get("/erp/purchase/requisitions", { search, status: status || undefined, company_id: company || undefined }, { preserveState: true, replace: true }); }} className="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <label className="text-sm">Requisition number<input value={search} maxLength={100} onChange={(e) => setSearch(e.target.value)} className="mt-1 w-full rounded-lg border p-2" placeholder="PR-2026-000001" /></label>
                    <label className="text-sm">Company<select value={company} onChange={(e) => setCompany(e.target.value)} className="mt-1 w-full rounded-lg border p-2"><option value="">All authorized companies</option>{companies.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                    <label className="text-sm">Status<select value={status} onChange={(e) => setStatus(e.target.value)} className="mt-1 w-full rounded-lg border p-2"><option value="">All statuses</option>{["draft", "submitted", "approved", "rejected"].map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
                    <button type="submit" className="self-end rounded-lg border px-4 py-2 text-sm">Apply filters</button>
                </form>
                <div className="overflow-x-auto rounded-xl border bg-white"><table className="w-full min-w-[700px] text-left text-sm"><thead className="border-b bg-slate-50"><tr>{["Number", "Company", "Department", "Required date", "Status", "Actions"].map((label) => <th key={label} className="p-4">{label}</th>)}</tr></thead><tbody>{requisitions.data.map((item) => <tr key={item.id} className="border-b last:border-0"><td className="p-4"><Link href={`/erp/purchase/requisitions/${item.id}`} className="font-medium text-indigo-700">{item.number}</Link></td><td className="p-4">{item.company?.name}</td><td className="p-4">{item.department?.name ?? "—"}</td><td className="p-4">{item.required_at?.slice(0, 10) ?? "—"}</td><td className="p-4"><span className="rounded bg-slate-100 px-2 py-1 text-xs capitalize">{item.status}</span></td><td className="p-4">{item.status === "draft" && can("erp.purchase-requisitions.edit") && <Link className="text-indigo-700" href={`/erp/purchase/requisitions/${item.id}/edit`}>Edit draft</Link>}</td></tr>)}{requisitions.data.length === 0 && <tr><td colSpan={6} className="p-8 text-center text-slate-500">No requisitions found.</td></tr>}</tbody></table></div>
                <div className="flex flex-wrap justify-between gap-3 text-sm"><p>{requisitions.total} requisitions · Page {requisitions.current_page} of {requisitions.last_page}</p><div className="flex gap-4">{requisitions.prev_page_url && <Link href={requisitions.prev_page_url}>Previous</Link>}{requisitions.next_page_url && <Link href={requisitions.next_page_url}>Next</Link>}</div></div>
            </div>
        </ERPLayout>
    );
}
