import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import { button, input } from "../Orders/types";

type Props = { companies: { id: number; name: string }[]; filters: { company_id?: string; search?: string }; orders: { data: { id: number; number: string; status: string; customer_name: string; document_date: string }[]; links: { url: string | null; label: string; active: boolean }[] } };
export default function Index({ companies, filters, orders }: Props) {
    const [company, setCompany] = useState(String(filters.company_id ?? ""));
    const [search, setSearch] = useState(filters.search ?? "");
    return <ERPLayout><Head title="Stock reservations" /><div className="space-y-6"><div><h1 className="text-2xl font-semibold">Stock reservations</h1><p className="mt-1 text-sm text-slate-500">Allocate available stock to confirmed sales orders. Partial allocations leave the shortage visible.</p></div>
        <form className="flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); router.get("/erp/sales/reservations", { company_id: company, search }); }}><label className="min-w-48 space-y-1 text-sm"><span>Company</span><select className={input} value={company} onChange={(event) => setCompany(event.target.value)}><option value="">All authorized companies</option>{companies.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label><label className="grow space-y-1 text-sm"><span>Order number</span><input className={input} value={search} maxLength={120} onChange={(event) => setSearch(event.target.value)} /></label><button className={button} type="submit">Filter</button></form>
        <div className="overflow-x-auto rounded-xl border bg-white"><table className="w-full min-w-[600px] text-left text-sm"><thead className="bg-slate-50"><tr>{["Order", "Customer", "Date", "Status"].map((label) => <th className="p-4" key={label}>{label}</th>)}</tr></thead><tbody>{orders.data.map((row) => <tr className="border-t" key={row.id}><td className="p-4"><Link className="font-medium text-indigo-700" href={`/erp/sales/reservations/${row.id}`}>{row.number}</Link></td><td className="p-4">{row.customer_name}</td><td className="p-4">{row.document_date}</td><td className="p-4 capitalize">{row.status.replace(/_/g, " ")}</td></tr>)}{orders.data.length === 0 && <tr><td className="p-6 text-slate-500" colSpan={4}>No confirmed orders found.</td></tr>}</tbody></table></div>
        <nav aria-label="Pagination" className="flex flex-wrap gap-2">{orders.links.map((link, index) => link.url ? <Link className={`${button} ${link.active ? "border-indigo-500 text-indigo-700" : ""}`} href={link.url} key={index} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span className="px-3 py-2 text-sm text-slate-400" key={index} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>
    </div></ERPLayout>;
}
