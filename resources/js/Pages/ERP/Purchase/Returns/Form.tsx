import { Head, Link, router, useForm } from "@inertiajs/react";
import ERPLayout from "@/Layouts/erp-layout";
import type { PurchaseReturn, ReturnLine, SourceLine } from "./types";
import { box, button } from "./types";

type Props = {
    purchaseReturn: PurchaseReturn | null; companies: { id: number; name: string }[]; receipts: { id: number; number: string }[]; companyId: number | null;
    receipt: { id: number; number: string; warehouse?: { name: string }; purchase_order?: { number: string; currency_code: string } } | null;
    sourceLines: SourceLine[]; returnItems: ReturnLine[];
};
function Editor({ purchaseReturn, receipt, sourceLines, returnItems }: Pick<Props, "purchaseReturn" | "receipt" | "sourceLines" | "returnItems">) {
    const form = useForm({
        goods_receipt_id: receipt?.id ?? 0,
        revision: purchaseReturn?.revision ?? 1,
        reason: purchaseReturn?.reason ?? "",
        items: sourceLines.map((line) => ({ goods_receipt_item_id: line.id, quantity: returnItems.find((item) => item.goods_receipt_item_id === line.id)?.quantity ?? "0" })),
    });
    const errors = form.errors as Record<string, string | undefined>;
    return <form className="space-y-5" onSubmit={(e) => {
        e.preventDefault();
        const items = form.data.items.filter((line) => Number(line.quantity) > 0);
        if (purchaseReturn) {
            form.transform((data) => ({ revision: data.revision, reason: data.reason, items }));
            form.put(`/erp/purchase/returns/${purchaseReturn.id}`);
        } else {
            form.transform((data) => ({ goods_receipt_id: data.goods_receipt_id, reason: data.reason, items }));
            form.post("/erp/purchase/returns");
        }
    }}>
        {Object.entries(errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-red-600">{message}</p>)}
        <section className="rounded-xl border bg-white p-5 text-sm"><p>Source GRN: {receipt?.number}</p><p>Purchase order: {receipt?.purchase_order?.number}</p><p>Return warehouse: {receipt?.warehouse?.name}</p><p className="mt-3 text-slate-500">Only accepted quantities can be returned. Drafts and approvals do not reserve stock or return capacity; posting checks both again.</p></section>
        <label className="block rounded-xl border bg-white p-5 text-sm">Return reason<textarea required maxLength={2000} rows={3} value={form.data.reason} onChange={(e) => form.setData("reason", e.target.value)} className={box} /></label>
        <div className="overflow-x-auto rounded-xl border bg-white"><table className="w-full min-w-[780px] text-left text-sm"><thead className="bg-slate-50"><tr>{["Product / variant", "Accepted", "Already returned", "Remaining", "GRN unit cost", "Return quantity"].map((label) => <th key={label} className="p-4">{label}</th>)}</tr></thead><tbody>{sourceLines.map((line, index) => <tr key={line.id} className="border-t"><td className="p-4">{line.product_name} {line.variant_name}<p className="text-xs text-slate-500">{line.is_stock_item ? "Stock item" : "Non-stock item"}</p></td><td className="p-4">{line.accepted}</td><td className="p-4">{line.returned}</td><td className="p-4">{line.remaining}</td><td className="p-4">{line.unit_cost}</td><td className="p-4"><input aria-label={`Return quantity for ${line.product_name} ${line.variant_name ?? ""}`} type="number" min="0" max={Math.max(0, Number(line.remaining))} step="0.0001" value={form.data.items[index]?.quantity ?? "0"} onChange={(e) => form.setData("items", form.data.items.map((item, i) => i === index ? { ...item, quantity: e.target.value } : item))} className={box} /></td></tr>)}</tbody></table></div>
        <p className="text-sm text-slate-500">Costs shown are in {receipt?.purchase_order?.currency_code}. Stock leaves at the warehouse average cost when posted. Non-stock returns have no stock movement.</p>
        <button type="submit" disabled={form.processing || !form.data.reason.trim() || !form.data.items.some((line) => Number(line.quantity) > 0)} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white disabled:opacity-50">{purchaseReturn ? "Save draft changes" : "Save return draft"}</button>
    </form>;
}
export default function Form(props: Props) {
    const { purchaseReturn, companies, receipts, companyId, receipt } = props;
    const title = purchaseReturn ? "Edit purchase return" : "New purchase return";
    return <ERPLayout><Head title={title} /><div className="mx-auto max-w-6xl space-y-6"><div><Link href={purchaseReturn ? `/erp/purchase/returns/${purchaseReturn.id}` : "/erp/purchase/returns"} className="text-sm text-indigo-700">Back</Link><h1 className="mt-2 text-2xl font-semibold">{title}</h1></div>
        {!purchaseReturn && <section className="grid gap-4 rounded-xl border bg-white p-5 sm:grid-cols-2"><label className="text-sm">Company<select value={companyId ?? ""} onChange={(e) => router.get("/erp/purchase/returns/create", { company_id: e.target.value || undefined })} className={box}><option value="">Select company</option>{companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}</select></label><label className="text-sm">Posted goods receipt<select value={receipt?.id ?? ""} disabled={!companyId} onChange={(e) => router.get("/erp/purchase/returns/create", { company_id: companyId ?? undefined, receipt_id: e.target.value || undefined })} className={box}><option value="">Select posted receipt</option>{receipts.map((item) => <option key={item.id} value={item.id}>{item.number}</option>)}</select></label>{companyId && receipts.length === 0 && <p className="text-sm text-slate-500">No posted goods receipts are available for this company.</p>}</section>}
        {receipt ? <Editor key={`${purchaseReturn?.revision ?? "new"}-${receipt.id}`} {...props} /> : <p className="rounded-lg bg-slate-50 p-4 text-sm">Select a company and posted GRN to load its accepted products.</p>}
    </div></ERPLayout>;
}
