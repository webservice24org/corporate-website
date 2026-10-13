import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import { box, button } from "./types";
import type { Rfq, Company } from "./types";

type Props = { rfqs: { data: Rfq[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null }; companies: Company[]; filters: { search?: string; company_id?: string | number; status?: string } };
export default function Index({ rfqs, companies, filters }: Props) {
    const { can } = useAuthorization();
    const [search, setSearch] = useState(filters.search ?? "");
    const [company, setCompany] = useState(String(filters.company_id ?? ""));
    const [status, setStatus] = useState(filters.status ?? "");
    return <ERPLayout><Head title="RFQs and quotations" /><div className="space-y-6">
        <div className="flex flex-wrap justify-between gap-3"><div><h1 className="text-2xl font-semibold">RFQs & supplier quotations</h1><p className="mt-1 text-sm text-slate-500">Request offers, compare prices and record your selection.</p></div>{can("erp.purchase-rfqs.create") && <Link href="/erp/purchase/rfqs/create" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white">New RFQ</Link>}</div>
        <form onSubmit={(e) => { e.preventDefault(); router.get("/erp/purchase/rfqs", { search, company_id: company || undefined, status: status || undefined }, { preserveState: true, replace: true }); }} className="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2 xl:grid-cols-4"><label className="text-sm">RFQ number<input maxLength={100} value={search} onChange={(e) => setSearch(e.target.value)} className={box} /></label><label className="text-sm">Company<select value={company} onChange={(e) => setCompany(e.target.value)} className={box}><option value="">All authorized companies</option>{companies.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label className="text-sm">Status<select value={status} onChange={(e) => setStatus(e.target.value)} className={box}><option value="">All statuses</option>{["draft", "issued", "awarded"].map((value) => <option key={value}>{value}</option>)}</select></label><button type="submit" className={`${button} self-end`}>Apply filters</button></form>
        <div className="overflow-x-auto rounded-xl border bg-white"><table className="w-full min-w-[650px] text-left text-sm"><thead className="border-b bg-slate-50"><tr>{["Number", "Company", "Currency", "Deadline", "Status", "Actions"].map((label) => <th key={label} className="p-4">{label}</th>)}</tr></thead><tbody>{rfqs.data.map((item) => <tr key={item.id} className="border-b"><td className="p-4"><Link href={`/erp/purchase/rfqs/${item.id}`} className="font-medium text-indigo-700">{item.number}</Link></td><td className="p-4">{item.company?.name}</td><td className="p-4">{item.currency_code}</td><td className="p-4">{item.deadline_at?.slice(0, 10) ?? "—"}</td><td className="p-4 capitalize">{item.status}</td><td className="p-4">{item.status === "draft" && can("erp.purchase-rfqs.edit") && <Link href={`/erp/purchase/rfqs/${item.id}/edit`} className="text-indigo-700">Edit</Link>}</td></tr>)}{rfqs.data.length === 0 && <tr><td colSpan={6} className="p-8 text-center text-slate-500">No RFQs found.</td></tr>}</tbody></table></div>
        <div className="flex flex-wrap justify-between gap-3 text-sm"><p>{rfqs.total} RFQs · Page {rfqs.current_page} of {rfqs.last_page}</p><div className="flex gap-4">{rfqs.prev_page_url && <Link href={rfqs.prev_page_url}>Previous</Link>}{rfqs.next_page_url && <Link href={rfqs.next_page_url}>Next</Link>}</div></div>
    </div></ERPLayout>;
}
