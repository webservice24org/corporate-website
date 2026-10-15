import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import type { FormEvent } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import type { Company, Customer } from "./types";
import { button, input } from "./types";

type Filters = { company_id: number | null; search: string; status: "active" | "inactive" | "all" };
type Props = { companies: Company[]; filters: Filters; errors?: Record<string, string>; customers: { data: Customer[]; total: number; from: number | null; to: number | null; prev_page_url: string | null; next_page_url: string | null } };

function FiltersForm({ companies, filters }: Pick<Props, "companies" | "filters">) {
    const [companyId, setCompanyId] = useState(String(filters.company_id ?? ""));
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get("/erp/sales/customers", { company_id: companyId || undefined, search, status }, { preserveScroll: true });
    };
    return <form onSubmit={submit} className="grid gap-4 rounded-xl border bg-white p-5 md:grid-cols-4">
        <label className="space-y-1 text-sm"><span>Company</span><select className={input} value={companyId} onChange={(event) => setCompanyId(event.target.value)} disabled={companies.length === 0}>
            {companies.length === 0 && <option value="">No companies available</option>}{companies.map((company) => <option key={company.id} value={company.id}>{company.name}{company.status !== "active" ? " (inactive)" : ""}</option>)}
        </select></label>
        <label className="space-y-1 text-sm"><span>Search customers</span><input className={input} value={search} maxLength={120} onChange={(event) => setSearch(event.target.value)} placeholder="Name, business or code" /></label>
        <label className="space-y-1 text-sm"><span>Status</span><select className={input} value={status} onChange={(event) => setStatus(event.target.value as Filters["status"])}><option value="active">Active</option><option value="inactive">Inactive</option><option value="all">All</option></select></label>
        <div className="flex items-end"><button type="submit" className={button} disabled={companies.length === 0}>Apply filters</button></div>
    </form>;
}

export default function Index({ companies, customers, filters, errors = {} }: Props) {
    const company = companies.find((item) => item.id === filters.company_id);
    return <ERPLayout><Head title="Sales customers" /><div className="space-y-6">
        <div><h1 className="text-2xl font-semibold">Sales customers</h1><p className="mt-1 text-sm text-slate-500">Customer contacts, addresses and commercial terms for {company?.name ?? "your company"}.</p></div>
        <FiltersForm key={`${filters.company_id}-${filters.search}-${filters.status}`} companies={companies} filters={filters} />
        {Object.entries(errors).map(([field, message]) => <p key={field} role="alert" className="text-sm text-red-600">{message}</p>)}
        <div className="overflow-x-auto rounded-xl border bg-white"><table className="w-full min-w-[750px] text-left text-sm"><thead className="bg-slate-50"><tr>{["Customer", "Code", "Phone", "Credit limit", "Payment terms", "Status"].map((label) => <th key={label} className="p-4">{label}</th>)}</tr></thead><tbody>
            {customers.data.map((customer) => <tr key={customer.id} className="border-t"><td className="p-4"><Link className="font-medium text-indigo-700 hover:underline" href={`/erp/sales/customers/${customer.id}?company_id=${customer.company_id}`}>{customer.name}</Link>{customer.business_name && <p className="mt-1 text-xs text-slate-500">{customer.business_name}</p>}</td><td className="p-4">{customer.customer_code ?? "—"}</td><td className="p-4">{customer.phone}</td><td className="p-4 whitespace-nowrap">{customer.credit_limit} {company?.currency_code}</td><td className="p-4">{customer.payment_terms_days} days</td><td className="p-4">{customer.is_active ? "Active" : "Inactive"}</td></tr>)}
            {customers.data.length === 0 && <tr><td colSpan={6} className="p-8 text-center text-slate-500">No customers match these filters.</td></tr>}
        </tbody></table><div className="flex flex-wrap items-center justify-between gap-3 border-t p-4"><p className="text-sm text-slate-500">{customers.from ?? 0}–{customers.to ?? 0} of {customers.total}</p><div className="flex gap-2">{customers.prev_page_url && <Link href={customers.prev_page_url} className={button}>Previous</Link>}{customers.next_page_url && <Link href={customers.next_page_url} className={button}>Next</Link>}</div></div></div>
    </div></ERPLayout>;
}
