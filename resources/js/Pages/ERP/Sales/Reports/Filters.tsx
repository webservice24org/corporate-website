import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { button, input } from '../Orders/types';
import type { Filters, Options } from './types';
type Props = Options & { filters: Filters; dashboard?: boolean };
export default function ReportFilters({ filters, companies, customers, warehouses, creators, currencies, catalog, dashboard = false }: Props) {
    const errors = usePage().props.errors as Record<string, string> | undefined;
    const [form, setForm] = useState<Filters>(filters);
    const type = form.type ?? 'invoices';
    const current = !dashboard && catalog[type]?.current;
    const warehouse = !dashboard && ['deliveries', 'returns'].includes(type);
    return <form className="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2 xl:grid-cols-4" onSubmit={e => { e.preventDefault(); router.get(dashboard ? '/erp/sales/reports/dashboard' : '/erp/sales/reports', { ...form, page: undefined, print: undefined, warehouse_id: warehouse ? form.warehouse_id : '', status: dashboard ? '' : form.status }); }}>
        {!dashboard && <label className="text-sm">Register<select className={input} value={type} onChange={e => setForm(f => ({ ...f, type: e.target.value, status: '', warehouse_id: '' }))}>{Object.entries(catalog).map(([key, def]) => <option key={key} value={key}>{def.title}</option>)}</select></label>}
        <label className="text-sm">Company<select className={input} value={form.company_id ?? ''} onChange={e => { const value = e.target.value; setForm(f => ({ ...f, company_id: value, customer_id: '', warehouse_id: '', created_by: '' })); router.get(dashboard ? '/erp/sales/reports/dashboard' : '/erp/sales/reports', { ...form, company_id: value, customer_id: '', warehouse_id: '', created_by: '', page: undefined, print: undefined }); }}><option value="">All permitted companies</option>{companies.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
        <label className="text-sm">Customer<select className={input} value={form.customer_id ?? ''} onChange={e => setForm(f => ({ ...f, customer_id: e.target.value }))}><option value="">All customers</option>{customers.filter(c => !form.company_id || String(c.company_id) === String(form.company_id)).map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
        <label className="text-sm">Created by<select className={input} value={form.created_by ?? ''} onChange={e => setForm(f => ({ ...f, created_by: e.target.value }))}><option value="">All document creators</option>{creators.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
        <label className="text-sm">Currency<select className={input} value={form.currency ?? ''} onChange={e => setForm(f => ({ ...f, currency: e.target.value }))}><option value="">Separate totals for every currency</option>{currencies.map(c => <option key={c}>{c}</option>)}</select></label>
        {!dashboard && <label className="text-sm">Current status<select className={input} value={form.status ?? ''} onChange={e => setForm(f => ({ ...f, status: e.target.value }))}><option value="">All supported statuses</option>{catalog[type]?.statuses.map(s => <option key={s}>{s}</option>)}</select></label>}
        {warehouse && <label className="text-sm">Warehouse<select className={input} value={form.warehouse_id ?? ''} onChange={e => setForm(f => ({ ...f, warehouse_id: e.target.value }))}><option value="">All warehouses</option>{warehouses.filter(w => !form.company_id || String(w.company_id) === String(form.company_id)).map(w => <option key={w.id} value={w.id}>{w.name}</option>)}</select></label>}
        <label className="text-sm">From {current ? '(ignored for current snapshot)' : ''}<input className={input} type="date" required disabled={current} value={form.from} onChange={e => setForm(f => ({ ...f, from: e.target.value }))} /></label>
        <label className="text-sm">Through {current ? '(ignored for current snapshot)' : ''}<input className={input} type="date" required disabled={current} value={form.to} onChange={e => setForm(f => ({ ...f, to: e.target.value }))} /></label>
        <label className="text-sm">Document / customer / product<input className={input} maxLength={120} value={form.search ?? ''} onChange={e => setForm(f => ({ ...f, search: e.target.value }))} /></label><button className={`${button} self-end`}>Apply filters</button>
        {Object.entries(errors ?? {}).map(([key, message]) => <p className="text-sm text-red-600" role="alert" key={key}>{message}</p>)}
    </form>;
}
