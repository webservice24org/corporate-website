import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CompanySelect, Errors, Frame, StatusBadge } from './Shared';
import { base, button, input, money, panel, secondary, transferUrl } from './types';
import type { Context, RegisterProps } from './types';

function Filters({ context, filters }: { context: Context; filters: RegisterProps['filters'] }) {
    const form = useForm({ ...filters });
    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(transferUrl(context.company?.id, form.data), {}, { preserveScroll: true });
    }
    return (
        <form onSubmit={submit} className={`${panel} space-y-4`}>
            <div className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <label>Original transfer ID<input type="number" min="1" className={input} value={form.data.transfer_id} onChange={event => form.setData('transfer_id', event.target.value)} /></label>
                <label>Status<select className={input} value={form.data.status} onChange={event => form.setData('status', event.target.value)}><option value="">All statuses</option>{['draft', 'submitted', 'approved', 'posted', 'cancelled'].map(status => <option key={status} value={status}>{status}</option>)}</select></label>

                <label>From<input className={input} type="date" min="1900-01-01" value={form.data.from} onChange={event => form.setData('from', event.target.value)} /></label>
                <label>To<input className={input} type="date" min={form.data.from || '1900-01-01'} value={form.data.to} onChange={event => form.setData('to', event.target.value)} /></label>
            </div>
            <div className="flex flex-wrap gap-3"><button className={button} disabled={!context.company}>Apply filters</button><Link href={transferUrl(context.company?.id)} className={secondary}>Reset</Link></div>
            <Errors errors={context.errors ?? {}} />
        </form>
    );
}

export default function Index(props: RegisterProps) {
    const { reversals, company, filters } = props;
    return (
        <Frame title="Transfer Reversals" context={props}>
            <CompanySelect context={props} />
            {props.canCreate && company && <CreateLookup companyId={company.id} />}
            <Filters key={`${company?.id ?? 0}:${JSON.stringify(filters)}`} context={props} filters={filters} />
            <section className={`${panel} overflow-x-auto`}>
                <table className="w-full text-left text-sm">
                    <caption className="sr-only">Transfer reversal register</caption>
                    <thead><tr>{['Reversal', 'Date', 'Original transfer', 'Amount', 'Status'].map(heading => <th scope="col" key={heading} className="whitespace-nowrap p-3">{heading}</th>)}</tr></thead>
                    <tbody>
                        {reversals.data.map(row => (
                            <tr key={row.id} className="border-t border-slate-200 dark:border-slate-700">
                                <td className="p-3"><Link href={`${base}/${row.id}`} className="font-medium underline">{row.number}</Link><span className="mt-1 block text-xs text-slate-500">Revision {row.revision}</span></td>
                                <td className="whitespace-nowrap p-3">{row.document_date}</td>
                                <td className="p-3">Transfer #{row.original_transfer_id}</td>
                                <td className="whitespace-nowrap p-3 font-mono">{money(row.amount)} {row.currency_code}</td>
                                <td className="p-3"><StatusBadge status={row.status} /></td>
                            </tr>
                        ))}
                        {reversals.data.length === 0 && <tr><td colSpan={5} className="p-6 text-center text-slate-500">{company ? 'No reversals match these filters.' : 'Select a company to view reversals.'}</td></tr>}
                    </tbody>
                </table>
            </section>
            <nav aria-label="Reversal pages" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                <span>{reversals.total} reversals · Page {reversals.current_page} of {reversals.last_page}</span>
                <div className="flex gap-4">
                    {reversals.current_page > 1 && <Link href={transferUrl(company?.id, filters, reversals.current_page - 1)}>Previous</Link>}
                    {reversals.current_page < reversals.last_page && <Link href={transferUrl(company?.id, filters, reversals.current_page + 1)}>Next</Link>}
                </div>
            </nav>
        </Frame>
    );
}

function CreateLookup({ companyId }: { companyId: number }) {
    const form = useForm({ transfer_id: '' });
    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(`${base}/create`, { company_id: companyId, transfer_id: form.data.transfer_id });
    }
    return (
        <form onSubmit={submit} className={`${panel} flex flex-wrap items-end gap-3`}>
            <label className="text-sm">Posted transfer ID<input type="number" min="1" required className={input} value={form.data.transfer_id} onChange={event => form.setData('transfer_id', event.target.value)} /></label>
            <button className={button}>Prepare reversal</button>
            <p className="w-full text-sm text-slate-500">You can also start from a posted transfer’s detail screen.</p>
        </form>
    );
}
