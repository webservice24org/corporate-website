import { Head, Link, router } from '@inertiajs/react';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { useAuthorization } from '@/hooks/useAuthorization';
import DeleteConfirmDialog from '@/Components/Common/DeleteConfirmDialog';
import { base, button, panel, date, number, Status, type Company, type Warehouse, type OpeningStock } from './shared';

type Filters = { search: string; company_id: string | number; warehouse_id: string | number; status: string; date_from: string; date_to: string; per_page: number };
type Props = {
    openingStocks: { data: OpeningStock[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null; links: { url: string | null; label: string; active: boolean }[] };
    filters: Filters; companies: Company[]; warehouses: Warehouse[]; statuses: string[];
};
const input = 'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400';
export default function Index({ openingStocks, filters, companies, warehouses, statuses }: Props) {
    const { can } = useAuthorization();
    const [values, setValues] = useState(filters);
    const [selected, setSelected] = useState<OpeningStock | null>(null);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    useEffect(() => { setValues(filters); }, [filters]);
    const availableWarehouses = warehouses.filter(w => !values.company_id || String(w.company_id) === String(values.company_id));
    const set = (key: keyof Filters, value: string | number) => setValues(v => ({ ...v, [key]: value }));
    function apply(event: FormEvent) {
        event.preventDefault();
        if (values.date_from && values.date_to && values.date_from > values.date_to) {
            setErrors({ dates: 'The start date must be on or before the end date.' }); return;
        }
        setErrors({});
        router.get(base, { ...values, search: values.search.trim() }, { preserveState: true, preserveScroll: true, replace: true });
    }
    function remove() {
        if (!selected || processing) return;
        setProcessing(true); setErrors({});
        router.delete(`${base}/${selected.id}`, {
            preserveScroll: true,
            onSuccess: () => setSelected(null),
            onError: setErrors,
            onFinish: () => setProcessing(false),
        });
    }
    const actions = (stock: OpeningStock) => <div className="flex flex-wrap justify-end gap-2">
        {can('erp.opening-stocks.view') && <Link className={button} href={`${base}/${stock.id}`} aria-label={`View ${stock.document_number}`} title="View"><Eye size={16} /></Link>}
        {stock.status === 'draft' && can('erp.opening-stocks.edit') && <Link className={button} href={`${base}/${stock.id}/edit`} aria-label={`Edit ${stock.document_number}`} title="Edit"><Pencil size={16} /></Link>}
        {stock.status === 'draft' && can('erp.opening-stocks.delete') && <button className={`${button} text-red-600`} onClick={() => { setSelected(stock); setErrors({}); }} aria-label={`Delete ${stock.document_number}`} title="Delete"><Trash2 size={16} /></button>}
    </div>;
    return <ERPLayout><Head title="Opening Stocks" /><div className="min-w-0 space-y-6">
        <header className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-bold">Opening Stocks</h1><p className="text-sm text-neutral-500">Manage opening inventory drafts and posted documents.</p></div>
            {can('erp.opening-stocks.create') && <Link className={button} href={`${base}/create`}><Plus size={16} />Create Opening Stock</Link>}
        </header>
        <form onSubmit={apply} className={`${panel} grid gap-4 sm:grid-cols-2 lg:grid-cols-4`}>
            <label className="space-y-1 text-sm">Search<input className={input} value={values.search} onChange={e => set('search', e.target.value)} placeholder="Document number or warehouse" /></label>
            <label className="space-y-1 text-sm">Company<select className={input} value={values.company_id} onChange={e => setValues(v => ({ ...v, company_id: e.target.value, warehouse_id: '' }))}><option value="">All companies</option>{companies.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
            <label className="space-y-1 text-sm">Warehouse<select className={input} value={values.warehouse_id} onChange={e => set('warehouse_id', e.target.value)}><option value="">All warehouses</option>{availableWarehouses.map(w => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}</select></label>
            <label className="space-y-1 text-sm">Status<select className={input} value={values.status} onChange={e => set('status', e.target.value)}><option value="">All statuses</option>{statuses.map(s => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}</select></label>
            <label className="space-y-1 text-sm">From date<input type="date" className={input} value={values.date_from} onChange={e => set('date_from', e.target.value)} /></label>
            <label className="space-y-1 text-sm">To date<input type="date" className={input} value={values.date_to} min={values.date_from || undefined} onChange={e => set('date_to', e.target.value)} /></label>
            <label className="space-y-1 text-sm">Rows per page<select className={input} value={values.per_page} onChange={e => set('per_page', Number(e.target.value))}>{[10, 15, 25, 50, 100].map(n => <option key={n} value={n}>{n}</option>)}</select></label>
            <div className="flex items-end gap-2"><button className={button} type="submit">Apply filters</button><button className={button} type="button" onClick={() => { setErrors({}); router.get(base, {}, { replace: true, preserveScroll: true, preserveState: false }); }}>Reset</button></div>
        </form>
        {Object.keys(errors).length > 0 && <div role="alert" className="rounded-lg bg-red-50 p-3 text-red-700">{Object.entries(errors).map(([key, message]) => <p key={key}>{message}</p>)}</div>}
        {openingStocks.data.length === 0 ? <div className={`${panel} py-12 text-center text-neutral-500`}>No opening stock documents match these filters.</div> : <>
            <div className="space-y-3 md:hidden">{openingStocks.data.map(s => <article key={s.id} className={panel}><div className="flex justify-between gap-2"><Link className="font-semibold hover:underline" href={`${base}/${s.id}`}>{s.document_number}</Link><Status status={s.status} /></div><dl className="my-3 grid grid-cols-2 gap-2 text-sm"><dt>Date</dt><dd>{date(s.document_date)}</dd><dt>Company</dt><dd>{s.company?.name ?? '—'}</dd><dt>Warehouse</dt><dd>{s.warehouse ? `${s.warehouse.code} — ${s.warehouse.name}` : '—'}</dd><dt>Items / quantity</dt><dd>{s.total_items} / {number(s.total_quantity)}</dd><dt>Total value</dt><dd>{number(s.total_value)}</dd></dl>{actions(s)}</article>)}</div>
            <div className={`${panel} hidden overflow-x-auto md:block`}><table className="w-full text-left text-sm"><thead><tr className="border-b">{['Document', 'Company / warehouse', 'Date', 'Status', 'Items', 'Quantity', 'Value', 'Actions'].map(h => <th key={h} className="whitespace-nowrap p-3">{h}</th>)}</tr></thead><tbody>{openingStocks.data.map(s => <tr key={s.id} className="border-b last:border-0"><td className="p-3"><Link className="font-semibold hover:underline" href={`${base}/${s.id}`}>{s.document_number}</Link><p className="text-xs text-neutral-500">By {s.creator?.name ?? '—'}</p></td><td className="p-3">{s.company?.name ?? '—'}<p className="text-xs text-neutral-500">{s.warehouse ? `${s.warehouse.code} — ${s.warehouse.name}` : '—'}</p></td><td className="whitespace-nowrap p-3">{date(s.document_date)}</td><td className="p-3"><Status status={s.status} /></td><td className="p-3 text-right">{s.total_items}</td><td className="p-3 text-right">{number(s.total_quantity)}</td><td className="p-3 text-right">{number(s.total_value)}</td><td className="p-3">{actions(s)}</td></tr>)}</tbody></table></div>
        </>}
        <footer className="flex flex-wrap items-center justify-between gap-3 text-sm"><p>Showing {openingStocks.from ?? 0}–{openingStocks.to ?? 0} of {openingStocks.total}</p><nav aria-label="Pagination" className="flex flex-wrap gap-1">{openingStocks.links.map((link, i) => {
            const label = link.label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»');
            return link.url ? <Link key={i} href={link.url} preserveScroll className={`${button} ${link.active ? 'font-bold ring-2 ring-blue-500' : ''}`} aria-current={link.active ? 'page' : undefined}>{label}</Link> : <span key={i} aria-disabled="true" className={`${button} opacity-50`}>{label}</span>;
        })}</nav></footer>
        <DeleteConfirmDialog open={selected !== null} onOpenChange={open => { if (!open && !processing) setSelected(null); }} onConfirm={remove} itemName={selected?.document_number} processing={processing} title="Delete opening stock draft?" />
    </div></ERPLayout>;
}
