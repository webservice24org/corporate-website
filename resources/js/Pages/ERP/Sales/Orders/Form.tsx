import axios from "axios";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { useState } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import type { Company, Item, Order, Source } from "./types";
import { button, input } from "./types";

type Props = { order: Order | null; companies: Company[]; items: Item[]; defaultCompanyId: number | null; defaultQuotationId: number | null; sources: Source[]; today: string };
export default function Form({ order, companies, items, defaultCompanyId, defaultQuotationId, sources: initial, today }: Props) {
    const form = useForm({ company_id: String(order?.company_id ?? defaultCompanyId ?? ""), sales_quotation_id: String(order?.sales_quotation_id ?? defaultQuotationId ?? ""), document_date: order?.document_date ?? today, expected_delivery_date: order?.expected_delivery_date ?? "", notes: order?.notes ?? "", revision: order?.revision ?? 1 });
    const [sources, setSources] = useState(initial);
    const [search, setSearch] = useState("");
    const [loading, setLoading] = useState(false);
    const [lookupError, setLookupError] = useState("");
    const selected = sources.find((source) => String(source.id) === form.data.sales_quotation_id);
    const load = async () => {
        setLoading(true); setLookupError("");
        try { const response = await axios.get<{ sources: Source[] }>("/erp/sales/orders/sources", { params: { company_id: form.data.company_id, search } }); setSources((old) => [...new Map([...old, ...response.data.sources].map((source) => [source.id, source])).values()]); }
        catch { setLookupError("Could not load accepted quotations. Check your access and try again."); } finally { setLoading(false); }
    };
    return <ERPLayout><Head title={order ? "Edit sales order" : "New sales order"} /><form className="space-y-6" onSubmit={(event) => { event.preventDefault(); if (order) form.put(`/erp/sales/orders/${order.id}`); else form.post("/erp/sales/orders"); }}>
        <div className="flex flex-wrap justify-between gap-3"><div><h1 className="text-2xl font-semibold">{order?.number ?? "New sales order"}</h1><p className="mt-1 text-sm text-slate-500">Items, prices and commercial terms come from the complete accepted quotation.</p></div><Link className={button} href="/erp/sales/orders">Sales orders</Link></div>
        {Object.entries(form.errors).map(([field, error]) => <p key={field} role="alert" className="text-sm text-red-600">{error}</p>)}
        <section className="grid gap-4 rounded-xl border bg-white p-5 md:grid-cols-2"><label className="space-y-1 text-sm"><span>Company</span>{order ? <p className="py-2">{companies.find((company) => company.id === order.company_id)?.name ?? "Selected company"}</p> : <select className={input} value={form.data.company_id} onChange={(event) => router.get("/erp/sales/orders/create", { company_id: event.target.value })}><option value="">Select company</option>{companies.map((company) => <option value={company.id} key={company.id}>{company.name}</option>)}</select>}</label>
            <label className="space-y-1 text-sm"><span>Accepted quotation</span>{order ? <p className="py-2">{order.terms_snapshot?.sales_order_source?.number ?? "Accepted source quotation"}</p> : <select className={input} required value={form.data.sales_quotation_id} onChange={(event) => form.setData("sales_quotation_id", event.target.value)}><option value="">Select quotation</option>{sources.map((source) => <option key={source.id} value={source.id}>{source.number} · {source.customer_name}</option>)}</select>}</label>
            <label className="space-y-1 text-sm"><span>Order date</span><input className={input} type="date" required disabled={!!order} value={form.data.document_date} onChange={(event) => form.setData("document_date", event.target.value)} /></label><label className="space-y-1 text-sm"><span>Expected delivery</span><input className={input} type="date" min={form.data.document_date} value={form.data.expected_delivery_date} onChange={(event) => form.setData("expected_delivery_date", event.target.value)} /></label>
            {!order && <div className="space-y-3 md:col-span-2"><div className="flex flex-wrap items-end gap-3"><label className="grow space-y-1 text-sm"><span>Find more accepted quotations</span><input className={input} maxLength={120} value={search} onChange={(event) => setSearch(event.target.value)} /></label><button type="button" className={button} disabled={loading || !form.data.company_id} onClick={() => void load()}>{loading ? "Loading…" : "Load quotations"}</button></div>{lookupError && <p role="alert" className="text-sm text-red-600">{lookupError}</p>}{selected && <p className="text-sm text-slate-600">{selected.customer_name} · {selected.grand_total} {selected.currency_code} · Valid until {selected.valid_until}</p>}</div>}
        </section>{order && <section className="overflow-x-auto rounded-xl border bg-white"><table className="w-full min-w-[600px] text-left text-sm"><thead className="bg-slate-50"><tr>{["Item", "Quantity", "Unit price", "Total"].map((label) => <th className="p-4" key={label}>{label}</th>)}</tr></thead><tbody>{items.map((item) => <tr className="border-t" key={item.id}><td className="p-4">{item.product_snapshot?.name}</td><td className="p-4">{item.quantity} {item.product_snapshot?.unit}</td><td className="p-4">{item.unit_price}</td><td className="p-4">{item.line_total}</td></tr>)}</tbody></table></section>}
        <label className="block space-y-1 text-sm"><span>Additional order notes</span><textarea className={input} maxLength={5000} rows={3} value={form.data.notes} onChange={(event) => form.setData("notes", event.target.value)} /></label><button className={button} type="submit" disabled={form.processing || loading || !form.data.company_id || (!order && !form.data.sales_quotation_id)}>{order ? "Save draft" : "Create order"}</button>
    </form></ERPLayout>;
}
