import { Head, Link, useForm } from "@inertiajs/react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import type { Requisition, RequisitionItem } from "@/types/purchase-requisition";

type Person = { id: number; name: string };
type History = { id: number; from_status: string | null; to_status: string; comment: string | null; created_at: string; actor_name: string | null };
type Change = { id: number; action: string; created_at: string; actor_name: string | null };
type Props = { requisition: Requisition; items: RequisitionItem[]; requester: Person | null; approver: Person | null; history: History[]; changes: Change[] };
function Actions({ requisition }: { requisition: Requisition }) {
    const { can, user } = useAuthorization();
    const form = useForm({ revision: Number(requisition.revision), comment: "" });
    const errorMessages = Object.values(form.errors);
    const submit = (action: "submit" | "approve" | "reject") => form.post(`/erp/purchase/requisitions/${requisition.id}/${action}`, { preserveScroll: true });
    const canSubmit = requisition.status === "draft" && can("erp.purchase-requisitions.submit");
    const canApprove = requisition.status === "submitted" && can("erp.purchase-requisitions.approve") && Number(user?.id) !== Number(requisition.requested_by);
    const canReject = requisition.status === "submitted" && can("erp.purchase-requisitions.reject");
    return (
        <section className="space-y-3 rounded-xl border bg-white p-5">
            <h2 className="font-semibold">Review actions</h2>
            {requisition.status === "draft" && <p className="text-sm text-slate-500">Review the items before submitting. Submission locks the draft for review.</p>}
            {requisition.status === "submitted" && Number(user?.id) === Number(requisition.requested_by) && <p className="text-sm text-amber-700">A different authorized user must approve your requisition.</p>}
            {requisition.status === "approved" && <p className="text-sm text-emerald-700">Approved. This requisition is ready for the RFQ milestone.</p>}
            {requisition.status === "rejected" && <p className="text-sm text-red-700">Rejected. Read the reason in the workflow history. Create a new requisition if the request needs revision.</p>}
            {errorMessages.length > 0 && <div role="alert" className="space-y-1 rounded-lg bg-red-50 p-3 text-sm text-red-700">{errorMessages.map((error, index) => <p key={index}>{error}</p>)}<Link href={`/erp/purchase/requisitions/${requisition.id}`} className="underline">Reload current requisition</Link></div>}
            {(canSubmit || canApprove || canReject) && <><label className="block text-sm">Comment (required for rejection)<textarea rows={3} maxLength={2000} value={form.data.comment} onChange={(e) => form.setData("comment", e.target.value)} className="mt-1 w-full rounded-lg border p-2" /></label><div className="flex flex-wrap gap-3">
                {canSubmit && <button type="button" disabled={form.processing} onClick={() => submit("submit")} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white disabled:opacity-50">Submit for review</button>}
                {canApprove && <button type="button" disabled={form.processing} onClick={() => submit("approve")} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm text-white disabled:opacity-50">Approve</button>}
                {canReject && <button type="button" disabled={form.processing || !form.data.comment.trim()} onClick={() => submit("reject")} className="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-700 disabled:opacity-50">Reject</button>}
            </div></>}
        </section>
    );
}
export default function Show({ requisition, items, requester, approver, history, changes }: Props) {
    const { can } = useAuthorization();
    const summary = [["Company", requisition.company?.name], ["Department", requisition.department?.name], ["Requested by", requester?.name], ["Approved by", approver?.name], ["Required date", requisition.required_at?.slice(0, 10)], ["Estimate currency", requisition.currency_code]];
    return (
        <ERPLayout>
            <Head title={requisition.number} />
            <div className="mx-auto max-w-6xl space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3"><div><Link href="/erp/purchase/requisitions" className="text-sm text-indigo-700">Back to requisitions</Link><h1 className="mt-2 text-2xl font-semibold">{requisition.number}</h1><p className="mt-1 text-sm capitalize text-slate-500">{requisition.status}</p></div>{requisition.status === "draft" && can("erp.purchase-requisitions.edit") && <Link href={`/erp/purchase/requisitions/${requisition.id}/edit`} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white">Edit draft</Link>}</div>
                <section className="rounded-xl border bg-white p-5"><dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">{summary.map(([label, value]) => <div key={label}><dt className="text-xs font-medium uppercase text-slate-500">{label}</dt><dd className="mt-1 text-sm">{value || "—"}</dd></div>)}</dl>{requisition.notes && <p className="mt-5 whitespace-pre-wrap break-words border-t pt-4 text-sm text-slate-600">{requisition.notes}</p>}</section>
                <section className="overflow-x-auto rounded-xl border bg-white"><table className="w-full min-w-[700px] text-left text-sm"><thead className="border-b bg-slate-50"><tr>{["Product", "Variant", "Quantity", `Estimated unit price (${requisition.currency_code ?? "—"})`, "Notes"].map((label) => <th key={label} className="p-4">{label}</th>)}</tr></thead><tbody>{items.map((item) => <tr key={item.id} className="border-b last:border-0"><td className="p-4">{item.product?.name ?? `Product #${item.product_id}`}</td><td className="p-4">{item.product_variant?.name ?? (item.product_variant_id ? `Variant #${item.product_variant_id}` : "—")}</td><td className="p-4">{item.quantity}</td><td className="p-4">{item.estimated_unit_price}</td><td className="max-w-xs whitespace-pre-wrap break-words p-4">{item.notes ?? "—"}</td></tr>)}</tbody></table><p className="border-t p-4 text-xs text-slate-500">Prices are estimates for review. No stock movement or supplier commitment is created by a requisition.</p></section>
                <Actions key={requisition.revision} requisition={requisition} />
                <div className="grid gap-6 lg:grid-cols-2"><section className="rounded-xl border bg-white p-5"><h2 className="mb-4 font-semibold">Workflow history</h2><ol className="space-y-4">{history.map((event) => <li key={event.id} className="border-l-2 border-indigo-200 pl-4"><p className="text-sm font-medium capitalize">{event.from_status ? `${event.from_status} → ` : ""}{event.to_status}</p><p className="mt-1 text-xs text-slate-500">{event.actor_name ?? "Unknown user"} · {event.created_at}</p>{event.comment && <p className="mt-2 whitespace-pre-wrap break-words text-sm">{event.comment}</p>}</li>)}</ol></section><section className="rounded-xl border bg-white p-5"><h2 className="mb-4 font-semibold">Recent changes</h2><ol className="space-y-3">{changes.map((change) => <li key={change.id} className="text-sm"><span className="font-medium capitalize">{change.action}</span><p className="text-xs text-slate-500">{change.actor_name ?? "Unknown user"} · {change.created_at}</p></li>)}</ol><p className="mt-4 text-xs text-slate-500">Showing up to 50 recent changes.</p></section></div>
            </div>
        </ERPLayout>
    );
}
