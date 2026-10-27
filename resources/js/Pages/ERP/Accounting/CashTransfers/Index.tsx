import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AccountLabel, CompanySelect, Errors, Frame, StatusBadge } from './Shared';
import { base, button, input, money, panel, secondary, transferUrl } from './types';
import type { Context, RegisterProps } from './types';

function Filters({ context, bindings, filters }: { context: Context; bindings: RegisterProps['bindings']; filters: RegisterProps['filters'] }) {
    const form = useForm({ ...filters });
    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(transferUrl(context.company?.id, form.data), {}, { preserveScroll: true });
    }
    return (
        <form onSubmit={submit} className={`${panel} space-y-4`}>
            <div className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-5">
                <label>Transfer number<input className={input} value={form.data.q} maxLength={40} onChange={event => form.setData('q', event.target.value)} /></label>
                <label>Status<select className={input} value={form.data.status} onChange={event => form.setData('status', event.target.value)}><option value="">All statuses</option>{['draft', 'submitted', 'approved', 'posted', 'cancelled'].map(status => <option key={status} value={status}>{status}</option>)}</select></label>
                <label>Either account<select className={input} value={form.data.binding_id} onChange={event => form.setData('binding_id', event.target.value)}><option value="">All accounts</option>{bindings.map(binding => <option key={binding.id} value={binding.id}>{binding.name}{binding.status !== 'active' ? ' (inactive)' : ''}</option>)}</select></label>
                <label>From<input className={input} type="date" min="1900-01-01" value={form.data.from} onChange={event => form.setData('from', event.target.value)} /></label>
                <label>To<input className={input} type="date" min={form.data.from || '1900-01-01'} value={form.data.to} onChange={event => form.setData('to', event.target.value)} /></label>
            </div>
            <div className="flex flex-wrap gap-3"><button className={button} disabled={!context.company}>Apply filters</button><Link href={transferUrl(context.company?.id)} className={secondary}>Reset</Link></div>
            <Errors errors={context.errors ?? {}} />
        </form>
    );
}

export default function Index(props: RegisterProps) {
    const { transfers, company, filters, bindings } = props;
    return (
        <Frame title="Cash and Bank Transfers" context={props}>
            <CompanySelect context={props} />
            {props.canCreate && company && <div><Link className={button} href={`${base}/create?company_id=${company.id}`}>New transfer</Link></div>}
            <Filters key={`${company?.id ?? 0}:${JSON.stringify(filters)}`} context={props} bindings={bindings} filters={filters} />
            <section className={`${panel} overflow-x-auto`}>
                <table className="w-full text-left text-sm">
                    <caption className="sr-only">Cash and bank transfer register</caption>
                    <thead><tr>{['Transfer', 'Date', 'Source', 'Destination', 'Amount', 'Status'].map(heading => <th scope="col" key={heading} className="whitespace-nowrap p-3">{heading}</th>)}</tr></thead>
                    <tbody>
                        {transfers.data.map(row => (
                            <tr key={row.id} className="border-t border-slate-200 dark:border-slate-700">
                                <td className="p-3"><Link href={`${base}/${row.id}`} className="font-medium underline">{row.number}</Link><span className="mt-1 block text-xs text-slate-500">Revision {row.revision}</span></td>
                                <td className="whitespace-nowrap p-3">{row.document_date}</td>
                                <td className="p-3"><AccountLabel binding={row.source} /></td>
                                <td className="p-3"><AccountLabel binding={row.destination} /></td>
                                <td className="whitespace-nowrap p-3 font-mono">{money(row.amount)} {row.currency_code}</td>
                                <td className="p-3"><StatusBadge status={row.status} /></td>
                            </tr>
                        ))}
                        {transfers.data.length === 0 && <tr><td colSpan={6} className="p-6 text-center text-slate-500">{company ? 'No transfers match these filters.' : 'Select a company to view transfers.'}</td></tr>}
                    </tbody>
                </table>
            </section>
            <nav aria-label="Transfer pages" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                <span>{transfers.total} transfers · Page {transfers.current_page} of {transfers.last_page}</span>
                <div className="flex gap-4">
                    {transfers.current_page > 1 && <Link href={transferUrl(company?.id, filters, transfers.current_page - 1)}>Previous</Link>}
                    {transfers.current_page < transfers.last_page && <Link href={transferUrl(company?.id, filters, transfers.current_page + 1)}>Next</Link>}
                </div>
            </nav>
        </Frame>
    );
}
