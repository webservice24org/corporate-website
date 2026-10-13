import { router, useForm } from "@inertiajs/react";
import { useState } from "react";
import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";

type Field = { key: string; label: string; required?: boolean; type?: string; max?: number };
export type SupplierDetailRecord = { id: number; title: string; values: Record<string, string | boolean> };
type Props = { supplierId: number; kind: "contacts" | "addresses"; records: SupplierDetailRecord[]; canManage: boolean };
const contactFields: Field[] = [
    { key: "name", label: "Contact name", required: true, max: 150 },
    { key: "job_title", label: "Job title", max: 255 },
    { key: "email", label: "Email", type: "email", max: 255 },
    { key: "phone", label: "Phone", max: 255 },
];
const addressFields: Field[] = [
    { key: "label", label: "Address label", required: true, max: 80 },
    { key: "address_line_1", label: "Address line 1", required: true, max: 255 },
    { key: "address_line_2", label: "Address line 2", max: 255 },
    { key: "city", label: "City", max: 255 },
    { key: "state", label: "State / division", max: 255 },
    { key: "postal_code", label: "Postal code", max: 255 },
    { key: "country", label: "Country", max: 255 },
];
export default function SupplierRecords({ supplierId, kind, records, canManage }: Props) {
    const contact = kind === "contacts";
    const fields = contact ? contactFields : addressFields;
    const flag = contact ? "is_primary" : "is_default";
    const defaults: Record<string, string | boolean> = Object.fromEntries(fields.map((field) => [field.key, ""]));
    defaults[flag] = false;
    const form = useForm<Record<string, string | boolean>>(defaults);
    const [editing, setEditing] = useState<number | null>(null);
    const [deleting, setDeleting] = useState<SupplierDetailRecord | null>(null);
    const [removing, setRemoving] = useState(false);
    const reset = () => { setEditing(null); form.reset(); form.clearErrors(); };
    const base = `/erp/purchase/suppliers/${supplierId}/${kind}`;
    const submit = () => {
        const options = { preserveScroll: true, onSuccess: reset };
        if (editing === null) form.post(base, options);
        else form.put(`${base}/${editing}`, options);
    };
    const remove = () => {
        if (!deleting) return;
        setRemoving(true);
        router.delete(`${base}/${deleting.id}`, { preserveScroll: true, onSuccess: () => { if (editing === deleting.id) reset(); setDeleting(null); }, onError: () => setDeleting(null), onFinish: () => setRemoving(false) });
    };
    return (
        <section className="space-y-4 rounded-xl border bg-white p-5">
            <h2 className="text-lg font-semibold">{contact ? "Contacts" : "Addresses"}</h2>
            <div className="grid gap-3 sm:grid-cols-2">
                {records.map((record) => <article key={record.id} className="space-y-2 rounded-lg border p-4">
                    <div className="flex flex-wrap items-center gap-2"><h3 className="font-medium">{record.title}</h3>{Boolean(record.values[flag]) && <span className="rounded bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700">{contact ? "Primary" : "Default"}</span>}</div>
                    <dl className="space-y-1 text-sm">{fields.filter((field) => field.key !== (contact ? "name" : "label") && record.values[field.key]).map((field) => <div key={field.key}><dt className="inline text-slate-500">{field.label}: </dt><dd className="inline break-words">{String(record.values[field.key])}</dd></div>)}</dl>
                    {canManage && <div className="flex gap-4 text-sm"><button type="button" className="text-indigo-700" onClick={() => { setEditing(record.id); form.clearErrors(); form.setData({ ...defaults, ...record.values }); }}>Edit</button><button type="button" className="text-red-700" onClick={() => setDeleting(record)}>Delete</button></div>}
                </article>)}
            </div>
            {records.length === 0 && <p className="text-sm text-slate-500">No {kind} added yet.</p>}
            {canManage && <form onSubmit={(e) => { e.preventDefault(); submit(); }} className="space-y-4 border-t pt-4">
                <h3 className="text-sm font-medium">{editing === null ? "Add" : "Edit"} {contact ? "contact" : "address"}</h3>
                <div className="grid gap-4 sm:grid-cols-2">{fields.map((field) => <label key={field.key} className="text-sm">{field.label}<input type={field.type ?? "text"} required={field.required} maxLength={field.max} value={String(form.data[field.key] ?? "")} onChange={(e) => form.setData(field.key, e.target.value)} className="mt-1 w-full rounded-lg border p-2" />{form.errors[field.key] && <p role="alert" className="mt-1 text-xs text-red-600">{form.errors[field.key]}</p>}</label>)}</div>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={Boolean(form.data[flag])} onChange={(e) => form.setData(flag, e.target.checked)} />{contact ? "Primary contact" : "Default address"}</label>
                {form.errors[flag] && <p role="alert" className="text-xs text-red-600">{form.errors[flag]}</p>}
                <div className="flex gap-3"><button type="submit" disabled={form.processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white disabled:opacity-50">{form.processing ? "Saving…" : "Save"}</button>{editing !== null && <button type="button" disabled={form.processing} onClick={reset} className="rounded-lg border px-4 py-2 text-sm">Cancel edit</button>}</div>
            </form>}
            <DeleteConfirmDialog open={deleting !== null} onOpenChange={(open) => { if (!open && !removing) setDeleting(null); }} onConfirm={remove} itemName={deleting?.title} processing={removing} />
        </section>
    );
}
