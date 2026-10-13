import axios from "axios";
import { Head, Link, useForm } from "@inertiajs/react";
import { useEffect, useState } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import type { Requisition, RequisitionCompany, RequisitionDepartment, RequisitionItem, RequisitionProduct } from "@/types/purchase-requisition";

type ItemForm = { product_id: string; product_variant_id: string; quantity: string; estimated_unit_price: string; notes: string };
type FormData = { company_id: string; department_id: string; required_at: string; notes: string; revision: number; items: ItemForm[] };
type Props = { requisition: Requisition | null; companies: RequisitionCompany[]; items: RequisitionItem[]; initialProducts: RequisitionProduct[]; initialDepartments?: RequisitionDepartment[]; initialHasMore?: boolean; defaultCompanyId?: number | null };
type Catalog = { products: RequisitionProduct[]; departments: RequisitionDepartment[]; has_more: boolean };
const blankItem = (): ItemForm => ({ product_id: "", product_variant_id: "", quantity: "1", estimated_unit_price: "0.00", notes: "" });

export default function Form({ requisition, companies, items, initialProducts, initialDepartments = [], initialHasMore = false, defaultCompanyId = null }: Props) {
    const form = useForm<FormData>({
        company_id: requisition ? String(requisition.company_id) : defaultCompanyId ? String(defaultCompanyId) : companies.length === 1 ? String(companies[0].id) : "",
        department_id: requisition?.department_id ? String(requisition.department_id) : "",
        required_at: requisition?.required_at?.slice(0, 10) ?? "", notes: requisition?.notes ?? "", revision: Number(requisition?.revision ?? 1),
        items: items.length ? items.map((item) => ({ product_id: String(item.product_id), product_variant_id: item.product_variant_id ? String(item.product_variant_id) : "", quantity: String(item.quantity), estimated_unit_price: String(item.estimated_unit_price), notes: item.notes ?? "" })) : [blankItem()],
    });
    const [products, setProducts] = useState<RequisitionProduct[]>(initialProducts);
    const [results, setResults] = useState<RequisitionProduct[]>(initialProducts);
    const [departments, setDepartments] = useState<RequisitionDepartment[]>(initialDepartments);
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(initialHasMore);
    const [retry, setRetry] = useState(0);
    const [loading, setLoading] = useState(false);
    const [lookupError, setLookupError] = useState("");
    const errors = form.errors as Record<string, string | undefined>;
    const currency = requisition?.currency_code ?? companies.find((company) => String(company.id) === form.data.company_id)?.currency_code ?? "";
    useEffect(() => {
        if (!form.data.company_id) return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            setLookupError("");
            axios.get<Catalog>("/erp/purchase/requisitions/catalog", {
                params: { company_id: form.data.company_id, search, page },
                signal: controller.signal,
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            })
                .then(({ data }) => {
                    if (!Array.isArray(data.products) || !Array.isArray(data.departments)) {
                        throw new Error("The product catalogue returned an unexpected response.");
                    }
                    return data;
                })
                .then((data) => {
                    if (controller.signal.aborted) return;
                    setResults(data.products);
                    setDepartments(data.departments);
                    setHasMore(data.has_more);
                    setProducts((previous) => Array.from(new Map([...previous, ...data.products].map((product) => [product.id, product])).values()));
                })
                .catch((error: unknown) => {
                    if (controller.signal.aborted) return;
                    const status = axios.isAxiosError(error) ? error.response?.status : undefined;
                    setLookupError(status === 403 ? "You cannot load products for this company. Check your company assignment and requisition permissions." : status === 404 ? "The product catalogue route was not found. Clear the Laravel route cache and retry." : "Could not load the product catalogue. Retry or reload the form.");
                })
                .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        }, 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [form.data.company_id, search, page, retry]);
    const updateItem = (index: number, patch: Partial<ItemForm>) => form.setData("items", form.data.items.map((item, i) => i === index ? { ...item, ...patch } : item));
    const submit = () => {
        if (requisition) {
            form.transform((data) => ({ ...data, company_id: undefined }));
            form.put(`/erp/purchase/requisitions/${requisition.id}`);
        } else {
            form.transform((data) => ({ ...data, revision: undefined }));
            form.post("/erp/purchase/requisitions");
        }
    };
    return (
        <ERPLayout>
            <Head title={requisition ? `Edit ${requisition.number}` : "New purchase requisition"} />
            <div className="mx-auto max-w-6xl space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-semibold">{requisition ? `Edit ${requisition.number}` : "New purchase requisition"}</h1><p className="mt-1 text-sm text-slate-500">Save a draft, then review and submit it from its detail page.</p></div><Link href="/erp/purchase/requisitions" className="text-sm text-indigo-700">Back to requisitions</Link></div>
                {!requisition && companies.length === 0 && <p role="alert" className="rounded-lg bg-amber-50 p-4 text-sm">No authorized company is available. Ask your administrator to assign your employee record.</p>}
                {errors.revision && <p role="alert" className="rounded-lg bg-amber-50 p-4 text-sm">{errors.revision} <Link href={`/erp/purchase/requisitions/${requisition?.id}/edit`} className="underline">Reload draft</Link></p>}
                {errors.status && <p role="alert" className="rounded-lg bg-red-50 p-4 text-sm">{errors.status}</p>}
                <form onSubmit={(e) => { e.preventDefault(); submit(); }} className="space-y-5 rounded-xl border bg-white p-5">
                    <fieldset disabled={form.processing} className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <label className="text-sm">Company{requisition ? <p className="mt-1 rounded-lg bg-slate-50 p-2">{requisition.company?.name} · {currency}</p> : <select required value={form.data.company_id} onChange={(e) => { form.setData({ ...form.data, company_id: e.target.value, department_id: "", items: [blankItem()] }); setProducts([]); setResults([]); setDepartments([]); setSearch(""); setPage(1); setHasMore(false); setLoading(false); setLookupError(""); }} className="mt-1 w-full rounded-lg border p-2"><option value="">Select company</option>{companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}</select>}{errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}</label>
                            <label className="text-sm">Department<select value={form.data.department_id} onChange={(e) => form.setData("department_id", e.target.value)} className="mt-1 w-full rounded-lg border p-2"><option value="">No department</option>{form.data.department_id && !departments.some((department) => String(department.id) === form.data.department_id) && <option value={form.data.department_id}>{requisition?.department?.name ?? "Selected department (check availability)"}</option>}{departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}</select>{errors.department_id && <p className="text-xs text-red-600">{errors.department_id}</p>}</label>
                            <label className="text-sm">Required date<input type="date" value={form.data.required_at} onChange={(e) => form.setData("required_at", e.target.value)} className="mt-1 w-full rounded-lg border p-2" />{errors.required_at && <p className="text-xs text-red-600">{errors.required_at}</p>}</label>
                        </div>
                        <div className="flex flex-wrap items-end gap-3 rounded-lg bg-slate-50 p-4"><label className="min-w-[200px] flex-1 text-sm">Find products<input disabled={!form.data.company_id} value={search} maxLength={100} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Search by name, SKU or code" className="mt-1 w-full rounded-lg border p-2" /></label><div className="flex items-center gap-3 text-sm"><button type="button" disabled={page === 1 || loading || !form.data.company_id} onClick={() => setPage((value) => value - 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Previous products</button><span>Page {page}</span><button type="button" disabled={!hasMore || loading || !form.data.company_id} onClick={() => setPage((value) => value + 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Next products</button></div></div>
                        {loading && <p role="status" className="text-sm text-slate-500">Loading products…</p>}{lookupError && <div role="alert" className="flex flex-wrap items-center gap-3 text-sm text-red-600"><p>{lookupError}</p>{!requisition && form.data.company_id && <Link href={`/erp/purchase/requisitions/create?company_id=${form.data.company_id}`} className="underline">Reload company products (resets draft)</Link>}<button type="button" onClick={() => setRetry((value) => value + 1)} className="underline">Retry catalogue</button></div>}{!loading && !lookupError && form.data.company_id && results.length === 0 && <p className="text-sm text-slate-500">No active products were found for this company and search. Check the product company and active status in Inventory.</p>}
                        {errors.items && <p role="alert" className="text-sm text-red-600">{errors.items}</p>}
                        <div className="space-y-4">{form.data.items.map((item, index) => {
                            const selected = products.find((product) => String(product.id) === item.product_id);
                            const options = selected && !results.some((product) => product.id === selected.id) ? [selected, ...results] : results;
                            return <section key={index} className="space-y-3 rounded-lg border p-4">
                                <div className="flex items-center justify-between gap-3"><h2 className="text-sm font-semibold">Item {index + 1}</h2><button type="button" disabled={form.data.items.length === 1} onClick={() => { form.setData("items", form.data.items.filter((_, i) => i !== index)); form.clearErrors(); }} className="text-sm text-red-700 disabled:opacity-40">Remove</button></div>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <label className="text-sm">Product<select required disabled={!form.data.company_id} value={item.product_id} onChange={(e) => { const product = products.find((entry) => String(entry.id) === e.target.value); updateItem(index, { product_id: e.target.value, product_variant_id: "", estimated_unit_price: product?.purchase_price ?? "0.00" }); }} className="mt-1 w-full rounded-lg border p-2"><option value="">Select product</option>{item.product_id && !selected && <option value={item.product_id}>Product #{item.product_id} (unavailable)</option>}{options.map((product) => <option key={product.id} value={product.id}>{product.name}{product.sku ? ` · ${product.sku}` : ""}</option>)}</select>{errors[`items.${index}.product_id`] && <p className="text-xs text-red-600">{errors[`items.${index}.product_id`]}</p>}</label>
                                    <label className="text-sm">Variant<select required={selected?.has_variants} disabled={!selected} value={item.product_variant_id} onChange={(e) => { const variant = selected?.variants.find((entry) => String(entry.id) === e.target.value); updateItem(index, { product_variant_id: e.target.value, estimated_unit_price: variant?.purchase_price ?? selected?.purchase_price ?? "0.00" }); }} className="mt-1 w-full rounded-lg border p-2"><option value="">{selected?.has_variants ? "Select variant" : "No variant"}</option>{item.product_variant_id && !selected?.variants.some((variant) => String(variant.id) === item.product_variant_id) && <option value={item.product_variant_id}>Variant #{item.product_variant_id} (unavailable)</option>}{selected?.variants.map((variant) => <option key={variant.id} value={variant.id}>{variant.name}</option>)}</select>{errors[`items.${index}.product_variant_id`] && <p className="text-xs text-red-600">{errors[`items.${index}.product_variant_id`]}</p>}</label>
                                    <label className="text-sm">Quantity<input type="number" required min="0.0001" max="1000000" step="0.0001" value={item.quantity} onChange={(e) => updateItem(index, { quantity: e.target.value })} className="mt-1 w-full rounded-lg border p-2" />{errors[`items.${index}.quantity`] && <p className="text-xs text-red-600">{errors[`items.${index}.quantity`]}</p>}</label>
                                    <label className="text-sm">Estimated unit price {currency && `(${currency})`}<input type="number" required min="0" max="1000000" step="0.01" value={item.estimated_unit_price} onChange={(e) => updateItem(index, { estimated_unit_price: e.target.value })} className="mt-1 w-full rounded-lg border p-2" />{errors[`items.${index}.estimated_unit_price`] && <p className="text-xs text-red-600">{errors[`items.${index}.estimated_unit_price`]}</p>}</label>
                                </div>
                                <label className="block text-sm">Item notes<input maxLength={2000} value={item.notes} onChange={(e) => updateItem(index, { notes: e.target.value })} className="mt-1 w-full rounded-lg border p-2" />{errors[`items.${index}.notes`] && <p className="text-xs text-red-600">{errors[`items.${index}.notes`]}</p>}</label>
                            </section>;
                        })}</div>
                        <button type="button" disabled={form.data.items.length >= 100 || !form.data.company_id} onClick={() => form.setData("items", [...form.data.items, blankItem()])} className="rounded-lg border px-4 py-2 text-sm disabled:opacity-40">Add item ({form.data.items.length}/100)</button>
                        <label className="block text-sm">Requisition notes<textarea rows={3} maxLength={10000} value={form.data.notes} onChange={(e) => form.setData("notes", e.target.value)} className="mt-1 w-full rounded-lg border p-2" />{errors.notes && <p className="text-xs text-red-600">{errors.notes}</p>}</label>
                        <div className="flex gap-3"><button type="submit" disabled={!form.data.company_id} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white disabled:opacity-50">{form.processing ? "Saving…" : "Save draft"}</button><Link href={requisition ? `/erp/purchase/requisitions/${requisition.id}` : "/erp/purchase/requisitions"} className="rounded-lg border px-4 py-2 text-sm">Cancel</Link></div>
                    </fieldset>
                </form>
            </div>
        </ERPLayout>
    );
}
