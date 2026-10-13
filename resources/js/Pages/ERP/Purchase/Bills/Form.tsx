import { Head, Link, router, useForm } from "@inertiajs/react";
import ERPLayout from "@/Layouts/erp-layout";
import type { Bill, BillLine, SourceLine } from "./types";
import { box } from "./types";

type Props = {
    bill: Bill | null; companies: { id: number; name: string }[]; receipts: { id: number; number: string }[]; companyId: number | null;
    receipt: { id: number; number: string; purchase_order?: { number: string; currency_code: string; supplier?: { name: string } } } | null;
    sourceLines: SourceLine[]; billItems: BillLine[]; invoiceDate: string; dueDate: string | null;
};
function Editor({ bill, receipt, sourceLines, billItems, invoiceDate, dueDate }: Props) {
    const form = useForm({ goods_receipt_id: receipt?.id ?? 0, revision: bill?.revision ?? 1, supplier_invoice_number: bill?.supplier_invoice_number ?? "", invoice_date: invoiceDate,
        due_date: dueDate ?? "", notes: bill?.notes ?? "", items: sourceLines.map((line) => ({ goods_receipt_item_id: line.id, quantity: billItems.find((item) => item.goods_receipt_item_id === line.id)?.quantity ?? "0" })) });
    const errors = form.errors as Record<string, string | undefined>;
    return <form className="space-y-5" onSubmit={(e) => {
        e.preventDefault();
        const items = form.data.items.filter((line) => Number(line.quantity) > 0);
        if (bill) {
            form.transform((data) => ({ revision: data.revision, supplier_invoice_number: data.supplier_invoice_number, invoice_date: data.invoice_date, due_date: data.due_date || null, notes: data.notes, items }));
            form.put(`/erp/purchase/bills/${bill.id}`);
        } else {
            form.transform((data) => ({ goods_receipt_id: data.goods_receipt_id, supplier_invoice_number: data.supplier_invoice_number, invoice_date: data.invoice_date, due_date: data.due_date || null, notes: data.notes, items }));
            form.post("/erp/purchase/bills");
        }
    }}>
        {Object.entries(errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-red-600">{message}</p>)}
        <section className="space-y-2 rounded-xl border bg-white p-5 text-sm"><p>Source GRN: {receipt?.number}</p><p>PO: {receipt?.purchase_order?.number}</p><p>Supplier: {receipt?.purchase_order?.supplier?.name}</p><p>Currency: {receipt?.purchase_order?.currency_code}</p><p className="text-slate-500">Accepted quantities less posted returns and approved bills are available. Drafts do not reserve billing capacity. Saving a bill does not change stock.</p></section>
        <section className="grid gap-4 rounded-xl border bg-white p-5 sm:grid-cols-3"><label className="text-sm">Supplier invoice number<input required maxLength={100} value={form.data.supplier_invoice_number} onChange={(e) => form.setData("supplier_invoice_number", e.target.value)} className={box} /></label><label className="text-sm">Invoice date<input required type="date" value={form.data.invoice_date} onChange={(e) => form.setData("invoice_date", e.target.value)} className={box} /></label><label className="text-sm">Due date (optional)<input type="date" min={form.data.invoice_date} value={form.data.due_date} onChange={(e) => form.setData("due_date", e.target.value)} className={box} /></label><label className="text-sm sm:col-span-3">Notes<textarea maxLength={10000} rows={3} value={form.data.notes} onChange={(e) => form.setData("notes", e.target.value)} className={box} /></label></section>
        <div className="overflow-x-auto rounded-xl border bg-white"><table className="w-full min-w-[850px] text-left text-sm"><thead className="bg-slate-50"><tr>{["Product / variant", "Accepted", "Returned", "Approved billed", "Available", "PO unit price", "Bill quantity"].map((label) => <th key={label} className="p-4">{label}</th>)}</tr></thead><tbody>{sourceLines.map((line, index) => <tr key={line.id} className="border-t"><td className="p-4">{line.product_name} {line.variant_name}</td><td className="p-4">{line.accepted}</td><td className="p-4">{line.returned}</td><td className="p-4">{line.billed}</td><td className="p-4">{line.remaining}</td><td className="p-4">{line.unit_price}</td><td className="p-4"><input aria-label={`Invoice quantity for ${line.product_name} ${line.variant_name ?? ""}`} type="number" min="0" max={line.remaining} step="0.0001" value={form.data.items[index]?.quantity ?? "0"} onChange={(e) => form.setData("items", form.data.items.map((item, i) => i === index ? { ...item, quantity: e.target.value } : item))} className={box} /></td></tr>)}</tbody></table></div>
        <p className="text-sm text-slate-500">PO prices apply. Line discounts and taxes are prorated to the billed quantity and rounded to two decimals. Review the server-calculated totals on the draft before approval. Invoice differences require corrected purchasing terms or a later adjustment workflow.</p>
        <button type="submit" disabled={form.processing || !form.data.supplier_invoice_number.trim() || !form.data.items.some((line) => Number(line.quantity) > 0)} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white disabled:opacity-50">{bill ? "Save bill changes" : "Save bill draft"}</button>
    </form>;
}
export default function Form(props: Props) {
    const { bill, companies, receipts, companyId, receipt } = props;
    const title = bill ? "Edit supplier bill" : "New supplier bill";
    return <ERPLayout><Head title={title} /><div className="mx-auto max-w-6xl space-y-6"><div><Link href={bill ? `/erp/purchase/bills/${bill.id}` : "/erp/purchase/bills"} className="text-sm text-indigo-700">Back</Link><h1 className="mt-2 text-2xl font-semibold">{title}</h1></div>
        {!bill && <section className="grid gap-4 rounded-xl border bg-white p-5 sm:grid-cols-2"><label className="text-sm">Company<select value={companyId ?? ""} onChange={(e) => router.get("/erp/purchase/bills/create", { company_id: e.target.value || undefined })} className={box}><option value="">Select company</option>{companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}</select></label><label className="text-sm">Posted GRN<select value={receipt?.id ?? ""} disabled={!companyId} onChange={(e) => router.get("/erp/purchase/bills/create", { company_id: companyId ?? undefined, receipt_id: e.target.value || undefined })} className={box}><option value="">Select posted receipt</option>{receipts.map((item) => <option key={item.id} value={item.id}>{item.number}</option>)}</select></label>{companyId && receipts.length === 0 && <p className="text-sm text-slate-500">No posted GRNs are available for this company.</p>}</section>}
        {receipt ? <Editor key={`${bill?.revision ?? "new"}-${receipt.id}`} {...props} /> : <p className="rounded-lg bg-slate-50 p-4 text-sm">Select a company and posted GRN to load billable products.</p>}
    </div></ERPLayout>;
}
