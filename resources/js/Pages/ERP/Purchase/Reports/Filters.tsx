import { router } from "@inertiajs/react";
import { useState } from "react";
import type { Filters, Options, ReportType } from "./types";
import { box, button, dateLabels, queryString, reportLabels } from "./types";

type Props = Options & { filters: Filters; target: string; reports?: boolean };
export default function FilterBar({ filters, companies, suppliers, currencies, target, reports = false }: Props) {
    const [company, setCompany] = useState(String(filters.company_id ?? ""));
    const [supplier, setSupplier] = useState(String(filters.supplier_id ?? ""));
    const [currency, setCurrency] = useState(filters.currency ?? "");
    const [from, setFrom] = useState(filters.from_date);
    const [to, setTo] = useState(filters.to_date);
    const [type, setType] = useState<ReportType>(filters.type);
    const current = reports && type === "payables";
    return <form onSubmit={(e) => {
        e.preventDefault();
        router.get(`${target}?${queryString({ company_id: company || undefined, supplier_id: supplier || undefined, currency: currency || undefined, from_date: from, to_date: to, type })}`);
    }} className="space-y-4 rounded-xl border bg-white p-5">
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"><label className="text-sm">Company<select value={company} onChange={(e) => { setCompany(e.target.value); setSupplier(""); }} className={box}><option value="">All authorized companies</option>{companies.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label className="text-sm">Supplier<select value={supplier} onChange={(e) => setSupplier(e.target.value)} className={box}><option value="">All suppliers</option>{suppliers.filter((item) => !company || item.company_id === Number(company)).map((item) => <option key={item.id} value={item.id}>{item.name} · Company #{item.company_id}</option>)}</select></label><label className="text-sm">Currency<select value={currency} onChange={(e) => setCurrency(e.target.value)} className={box}><option value="">All currencies, shown separately</option>{currencies.map((code) => <option key={code}>{code}</option>)}</select></label><label className="text-sm">From date<input required={!current} disabled={current} type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} className={box} /></label><label className="text-sm">To date<input required={!current} disabled={current} type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} className={box} /></label>{reports && <label className="text-sm">Report<select value={type} onChange={(e) => setType(e.target.value as ReportType)} className={box}>{(Object.keys(reportLabels) as ReportType[]).map((item) => <option key={item} value={item}>{reportLabels[item]}</option>)}</select></label>}</div>
        <div className="flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-slate-500">{reports ? dateLabels[type] : "Period activity uses release, receipt, return posting and bill approval dates. Pending work and unpaid balances are current."}</p><button type="submit" className={button}>Apply filters</button></div>
    </form>;
}
