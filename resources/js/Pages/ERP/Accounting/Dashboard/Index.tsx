import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { basisLabel, dashboardUrl, path } from './types';
import type { Card, Props } from './types';

const panel = 'rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900';
const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-600 dark:bg-slate-950';
const button = 'rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900';

export default function Index(props: Props) {
    const { company, companies, filters, today, dashboard, errors = {} } = props;
    const form = useForm({ ...filters });
    function submit(event: FormEvent) {
        event.preventDefault();
        if (company) router.get(dashboardUrl(company.id, form.data), {}, { preserveScroll: true });
    }
    const groups = [
        { basis: 'period', title: 'Period activity' },
        { basis: 'cutoff', title: 'Balances at the cutoff' },
        { basis: 'current', title: 'Current journal workflow' },
        { basis: 'review', title: 'Reports requiring review' },
    ];
    return (
        <ERPLayout>
            <Head title="Finance Dashboard" />
            <main className="space-y-6">
                <header className="rounded-2xl bg-slate-900 p-6 text-white">
                    <p className="text-sm text-slate-300">Accounting and finance</p>
                    <h1 className="mt-1 text-2xl font-semibold">Finance Dashboard</h1>
                    <p className="mt-2 max-w-3xl text-sm text-slate-300">Period activity, dated balances and current review work. Each figure keeps its source report definition and coverage.</p>
                </header>
                <label className={`${panel} block text-sm font-medium`}>
                    Company
                    <select className={input} value={company?.id ?? ''} onChange={event => router.get(path, event.target.value ? { company_id: event.target.value } : {})}>
                        <option value="">Select company</option>
                        {companies.map(row => <option key={row.id} value={row.id}>{row.code} — {row.name}{row.status !== 'active' ? ' (inactive)' : ''}</option>)}
                    </select>
                </label>
                {company && <form onSubmit={submit} className={`${panel} flex flex-wrap items-end gap-4`}>
                    <label className="min-w-40 flex-1 text-sm">From<input type="date" className={input} required min="1900-01-01" max={today ?? undefined} value={form.data.from} onChange={event => form.setData('from', event.target.value)} /></label>
                    <label className="min-w-40 flex-1 text-sm">Through / balance cutoff<input type="date" className={input} required min={form.data.from} max={today ?? undefined} value={form.data.to} onChange={event => form.setData('to', event.target.value)} /></label>
                    <button className={button}>Apply dates</button>
                    <Link className="py-2 text-sm underline" href={`${path}?company_id=${company.id}`}>Reset</Link>
                </form>}
                {Object.keys(errors).length > 0 && <ul role="alert" className="text-sm text-red-700 dark:text-red-300">{Object.entries(errors).map(([key, value]) => <li key={key}>{value}</li>)}</ul>}
                {!dashboard ? <p className={panel}>Select a company to view finance metrics.</p> : <>
                    <p className="text-sm text-slate-600 dark:text-slate-300">Currency: <strong>{dashboard.currency}</strong>. Metrics and links appear only with their separate report permissions. Amounts remain exact; no currencies are combined. {dashboard.coverage}</p>
                    {groups.map(group => {
                        const cards = dashboard.cards.filter(card => card.basis === group.basis);
                        return cards.length > 0 ? <section key={group.basis} aria-label={group.title} className="space-y-3">
                            <h2 className="text-lg font-semibold">{group.title}</h2>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{cards.map(card => <Metric key={card.id} card={card} currency={dashboard.currency} dates={basisLabel(card.basis, filters)} />)}</div>
                        </section> : null;
                    })}
                    {dashboard.cash_visible && !dashboard.cards.some(card => card.id.startsWith('cash_')) && <p className={panel}>No cash or bank bindings are configured for this company.</p>}
                    {dashboard.bank_visible && <section className={`${panel} space-y-3`}>
                        <h2 className="text-lg font-semibold">Bank reconciliation review</h2>
                        <p className="text-sm text-slate-500">Latest reconciliation statement per bank ending on or before {filters.to}. Each difference uses its own statement cutoff and current matching state. Zero difference does not mean independently finalized.</p>
                        {dashboard.bank_rows.length === 0 ? <p className="text-sm">No reconciliation statements through this cutoff.</p> : <div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Bank reconciliation differences by statement cutoff</caption><thead><tr>{['Bank', 'Statement cutoff', `Statement less book · ${dashboard.currency}`, 'Review'].map(title => <th key={title} className="p-3" scope="col">{title}</th>)}</tr></thead><tbody>{dashboard.bank_rows.map((row, index) => <tr key={row.id ?? `limit-${index}`} className="border-t border-slate-200 dark:border-slate-700"><td className="p-3">{row.bank_id === null ? 'Scope limit' : `Bank #${row.bank_id}`}</td><td className="p-3">{row.cutoff}</td><td className="p-3 font-mono">{row.difference ?? 'Unknown'}</td><td className="p-3"><Link className="underline" href={row.url}>{row.status.replaceAll('_', ' ')}</Link></td></tr>)}</tbody></table></div>}
                    </section>}
                    {dashboard.source_scan && <section className={`${panel} space-y-3`}>
                        <h2 className="text-lg font-semibold">Source posting review</h2>
                        <p className="text-sm text-slate-500">Current company evidence scan, independent of the selected activity dates. The first 50 candidates are inspected; this is not a company-wide exception total.</p>
                        {dashboard.source_scan.status === 'review' ? <p role="status">Source evidence needs review; no count is substituted.</p> : <>
                            <p className="text-sm"><strong>{dashboard.source_scan.inspected}</strong> inspected of {dashboard.source_scan.total_candidates} candidates · <strong>{dashboard.source_scan.needs_review}</strong> inspected items require review{dashboard.source_scan.has_more ? ' · More candidates remain uninspected.' : '.'}</p>
                            <ul className="flex flex-wrap gap-3 text-sm">{Object.entries(dashboard.source_scan.counts ?? {}).map(([status, count]) => <li key={status}>{status.replaceAll('_', ' ')}: {count}</li>)}</ul>
                        </>}
                        <Link className="text-sm underline" href={dashboard.source_scan.url}>Open complete source-review workspace</Link>
                    </section>}
                    {dashboard.links.length > 0 && <nav aria-label="Finance reports" className={`${panel} flex flex-wrap gap-4 text-sm`}>{dashboard.links.map(link => <Link key={link.url} href={link.url} className="underline">{link.label}</Link>)}</nav>}
                    {dashboard.cards.length === 0 && dashboard.links.length === 0 && !dashboard.bank_visible && !dashboard.source_scan && <p className={panel}>Your dashboard permission is active. Assign the relevant report view permissions to display finance metrics.</p>}
                </>}
            </main>
        </ERPLayout>
    );
}

function Metric({ card, currency, dates }: { card: Card; currency: string; dates: string }) {
    const review = card.status === 'review';
    return <article className={`${panel} ${review ? 'border-amber-400 dark:border-amber-500' : ''}`}>
        <p className="text-xs text-slate-500 dark:text-slate-400">{dates}</p>
        <h3 className="mt-2 font-medium">{card.label}</h3>
        <p className="mt-3 break-all font-mono text-2xl font-semibold">{card.value ?? 'Unknown'}{card.unit === 'money' && <span className="ml-2 text-xs font-normal">{currency}</span>}</p>
        <p className={`mt-2 text-xs ${review ? 'text-amber-800 dark:text-amber-300' : 'text-slate-500 dark:text-slate-400'}`}>{review ? 'Review required · provisional' : card.status.replaceAll('_', ' ')}</p>
        <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">{card.note}</p>
        <Link className="mt-4 inline-block text-sm underline" href={card.url}>Open report<span className="sr-only">: {card.label}</span></Link>
    </article>;
}
