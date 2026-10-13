import { Head, Link, useForm } from "@inertiajs/react";
import ERPLayout from "@/Layouts/erp-layout";
import type { Supplier, SupplierCompany } from "@/types/supplier";

type Props = { supplier: Supplier | null; companies: SupplierCompany[] };
export default function Form({ supplier, companies }: Props) {
    const form = useForm({
        company_id: supplier ? String(supplier.company_id) : companies.length === 1 ? String(companies[0].id) : "",
        code: supplier?.code ?? "", name: supplier?.name ?? "", currency_code: supplier?.currency_code ?? "BDT",
        status: supplier?.status ?? "active", email: supplier?.email ?? "", phone: supplier?.phone ?? "", tax_number: supplier?.tax_number ?? "",
        payment_terms_days: String(supplier?.payment_terms_days ?? 0), notes: supplier?.notes ?? "",
    });
    const fields = [
        { key: "code", label: "Supplier code", required: true, max: 60 },
        { key: "name", label: "Supplier name", required: true, max: 200 },
        { key: "currency_code", label: "Currency code", required: true, max: 3 },
        { key: "email", label: "Email", type: "email", max: 255 },
        { key: "phone", label: "Phone", max: 255 },
        { key: "tax_number", label: "Tax number", max: 255 },
        { key: "payment_terms_days", label: "Payment terms (days)", type: "number", required: true },
    ] as const;
    const submit = () => {
        if (supplier) {
            form.transform((data) => ({ ...data, company_id: undefined }));
            form.put(`/erp/purchase/suppliers/${supplier.id}`);
        } else {
            form.transform((data) => data);
            form.post("/erp/purchase/suppliers");
        }
    };
    return (
        <ERPLayout>
            <Head title={supplier ? "Edit supplier" : "Add supplier"} />
            <div className="mx-auto max-w-5xl space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3"><h1 className="text-2xl font-semibold">{supplier ? "Edit supplier" : "Add supplier"}</h1><Link href="/erp/purchase/suppliers" className="text-sm text-indigo-700">Back to suppliers</Link></div>
                {!supplier && companies.length === 0 && <p role="alert" className="rounded-lg bg-amber-50 p-4 text-sm">No authorized company is available. Ask your administrator to assign your employee record to a company.</p>}
                <form onSubmit={(e) => { e.preventDefault(); submit(); }} className="space-y-5 rounded-xl border bg-white p-5 sm:p-7">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <label className="text-sm">Company{supplier ? <p className="mt-1 rounded-lg bg-slate-50 p-2">{supplier.company?.name}</p> : <select required value={form.data.company_id} onChange={(e) => form.setData("company_id", e.target.value)} className="mt-1 w-full rounded-lg border p-2"><option value="">Select company</option>{companies.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>}{form.errors.company_id && <p className="mt-1 text-xs text-red-600">{form.errors.company_id}</p>}</label>
                        <label className="text-sm">Status<select value={form.data.status} onChange={(e) => form.setData("status", e.target.value as "active" | "inactive")} className="mt-1 w-full rounded-lg border p-2"><option value="active">Active</option><option value="inactive">Inactive</option></select>{form.errors.status && <p className="text-xs text-red-600">{form.errors.status}</p>}</label>
                        {fields.map((field) => <label key={field.key} className="text-sm">{field.label}<input required={"required" in field && field.required} type={"type" in field ? field.type : "text"} maxLength={"max" in field ? field.max : undefined} min={field.key === "payment_terms_days" ? 0 : undefined} max={field.key === "payment_terms_days" ? 3650 : undefined} step={field.key === "payment_terms_days" ? 1 : undefined} value={form.data[field.key]} onChange={(e) => form.setData(field.key, e.target.value)} className="mt-1 w-full rounded-lg border p-2" />{form.errors[field.key] && <p className="mt-1 text-xs text-red-600">{form.errors[field.key]}</p>}</label>)}
                    </div>
                    <label className="block text-sm">Notes<textarea maxLength={10000} rows={4} value={form.data.notes} onChange={(e) => form.setData("notes", e.target.value)} className="mt-1 w-full rounded-lg border p-2" />{form.errors.notes && <p className="text-xs text-red-600">{form.errors.notes}</p>}</label>
                    <div className="flex gap-3"><button type="submit" disabled={form.processing || (!supplier && companies.length === 0)} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white disabled:opacity-50">{form.processing ? "Saving…" : "Save supplier"}</button><Link href={supplier ? `/erp/purchase/suppliers/${supplier.id}` : "/erp/purchase/suppliers"} className="rounded-lg border px-4 py-2 text-sm">Cancel</Link></div>
                </form>
            </div>
        </ERPLayout>
    );
}
