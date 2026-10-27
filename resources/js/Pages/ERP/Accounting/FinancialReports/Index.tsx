import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { button, input, panel, paths, reportUrl, titles } from './types';
import type { Filters, Props, Report } from './types';

function FiltersForm({ props }: { props: Props }) {
    const [filters, setFilters] = useState<Filters>(props.filters);
    function submit(event: FormEvent) {
        event.preventDefault();
        if (!props.company) return;
        router.get(reportUrl(props.kind, props.company.id, filters), {}, { preserveState: false, preserveScroll: true });
    }
    return (
        <form className={`${panel} space-y-4`} onSubmit={submit}>
            <div className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                {props.kind === 'ledger' && <>
                    <label>Find account by code or name<input className={input} maxLength={100} value={filters.q} onChange={event => setFilters({ ...filters, q: event.target.value })} placeholder="Enter a search, then apply" /></label>
                    <label>Account<select className={input} value={filters.account_id} onChange={event => setFilters({ ...filters, account_id: event.target.value })}>
                        <option value="">Select an account</option>
                        {props.accounts.map(account => <option key={account.id} value={account.id}>{account.label}</option>)}
                    </select></label>
                </>}
                <label>From<input className={input} type="date" min="1900-01-01" max={props.today ?? undefined} required value={filters.from} onChange={event => setFilters({ ...filters, from: event.target.value })} /></label>
                <label>To<input className={input} type="date" min={filters.from || '1900-01-01'} max={props.today ?? undefined} required value={filters.to} onChange={event => setFilters({ ...filters, to: event.target.value })} /></label>
                {props.kind === 'ledger' && <label>Rows per page<select className={input} value={filters.per_page} onChange={event => setFilters({ ...filters, per_page: event.target.value })}>{[10, 25, 50, 100].map(size => <option key={size} value={size}>{size}</option>)}</select></label>}
            </div>
            {props.accountsTruncated && <p className="text-sm text-amber-700 dark:text-amber-300">Showing the first 500 matching accounts and your selected account. Refine the account search to find others.</p>}
            <div className="flex flex-wrap items-center gap-4">
                <button className={button} disabled={!props.company}>Apply filters / search</button>
                <Link className="text-sm underline" href={`${paths[props.kind]}${props.company ? `?company_id=${props.company.id}` : ''}`}>Reset</Link>
            </div>
        </form>
    );
}

function Ledger({ report, props }: { report: Report; props: Props }) {
    const page = report.page;
    return <>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {[['Opening balance', report.opening], ['Period debit', report.period_debit], ['Period credit', report.period_credit], ['Closing balance', report.closing]].map(([label, value]) => <div className={panel} key={label}><p className="text-sm text-slate-500">{label} · {report.currency}</p><p className="mt-2 break-all font-mono text-xl font-semibold">{value}</p></div>)}
        </div>
        <p className="text-sm text-slate-600 dark:text-slate-300">Signed balances: positive is debit, negative is credit. Totals cover all filtered rows.</p>
        <div className={`${panel} overflow-x-auto`}>
            <table className="w-full text-left text-sm">
                <caption className="mb-3 text-left font-semibold">{report.account?.code} — {report.account?.name}</caption>
                <thead><tr>{['Date', 'Journal', 'Line', 'Debit', 'Credit', 'Running balance'].map(label => <th className="whitespace-nowrap p-3" key={label} scope="col">{label}</th>)}</tr></thead>
                <tbody>{(report.data ?? []).map(line => <tr className="border-t border-slate-100 dark:border-slate-700" key={`${line.journal_id}:${line.line_number}`}><td className="whitespace-nowrap p-3">{line.date}</td><td className="p-3">{line.number}</td><td className="p-3">{line.line_number}</td>{[line.debit, line.credit, line.balance].map((amount, index) => <td key={index} className="whitespace-nowrap p-3 font-mono">{amount}</td>)}</tr>)}</tbody>
            </table>
            {!report.data?.length && <p className="p-3 text-sm">No posted activity on this page. Opening and closing balances still cover the selected history.</p>}
        </div>
        {page && props.company && <nav className="flex flex-wrap justify-between gap-3 text-sm" aria-label="Ledger pages"><span>{page.total} rows · Page {page.current_page} of {page.last_page}</span><div className="flex gap-4">{page.current_page > 1 && <Link href={reportUrl('ledger', props.company.id, props.filters, page.current_page - 1)}>Previous</Link>}{page.current_page < page.last_page && <Link href={reportUrl('ledger', props.company.id, props.filters, page.current_page + 1)}>Next</Link>}</div></nav>}
    </>;
}

function Trial({ report, props }: { report: Report; props: Props }) {
    const moneyKeys = ['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit'] as const;
    return <>
        <section role="status" className={`${panel} ${report.balanced ? 'border-emerald-400' : 'border-amber-400'}`}>
            <h2 className="font-semibold">{report.balanced ? 'Trial Balance balances within represented GL history' : 'Review required — Trial Balance differences detected'}</h2>
            <p className="mt-2 text-sm">Opening difference: {report.differences?.opening} · Period difference: {report.differences?.period} · Closing difference: {report.differences?.closing} {report.currency}</p>
        </section>
        <div className={`${panel} overflow-x-auto`}>
            <table className="w-full text-left text-sm">
                <caption className="mb-3 text-left font-semibold">All company accounts · {report.currency} · inactive and zero balances included</caption>
                <thead><tr>{['Code', 'Account', 'Opening debit', 'Opening credit', 'Period debit', 'Period credit', 'Closing debit', 'Closing credit'].map(label => <th className="whitespace-nowrap p-3" key={label} scope="col">{label}</th>)}</tr></thead>
                <tbody>{(report.accounts ?? []).map(account => <tr className="border-t border-slate-100 dark:border-slate-700" key={account.id}><td className="p-3">{account.code}</td><th scope="row" className="min-w-48 p-3 font-medium">{props.canLedger && props.company ? <Link className="text-indigo-700 underline dark:text-indigo-300" href={reportUrl('ledger', props.company.id, { ...props.filters, account_id: String(account.id), q: '' })}>{account.name}</Link> : account.name}{account.status !== 'active' && <span className="ml-2 text-xs text-slate-500">Inactive</span>}</th>{moneyKeys.map(key => <td className="whitespace-nowrap p-3 font-mono" key={key}>{account[key]}</td>)}</tr>)}</tbody>
                <tfoot><tr className="border-t-2 font-semibold"><th className="p-3" scope="row" colSpan={2}>Totals</th>{moneyKeys.map(key => <td className="whitespace-nowrap p-3 font-mono" key={key}>{report.totals?.[key]}</td>)}</tr></tfoot>
            </table>
        </div>
    </>;
}

export default function Index(props: Props) {
    const title = titles[props.kind];
    return <ERPLayout><Head title={title} /><div className="space-y-5">
        <header><h1 className="text-2xl font-semibold">{title}</h1><p className="mt-1 text-sm text-slate-500">Posted journals in the company currency, by accounting date.</p></header>
        <nav className="flex flex-wrap gap-4 text-sm" aria-label="Financial reports">{props.canCashFlow && <Link className="underline" href={`/erp/accounting/cash-flow${props.company ? `?company_id=${props.company.id}&from=${props.filters.from}&to=${props.filters.to}` : ''}`}>Cash Flow</Link>}{props.canBalance && <Link className="underline" href={`/erp/accounting/balance-sheet${props.company ? `?company_id=${props.company.id}&as_of=${props.filters.to}` : ''}`}>Balance Sheet</Link>}{props.canProfit && <Link className="underline" href={`/erp/accounting/profit-loss${props.company ? `?company_id=${props.company.id}&from=${props.filters.from}&to=${props.filters.to}` : ''}`}>Profit and Loss</Link>}{props.canLedger && <Link aria-current={props.kind === 'ledger' ? 'page' : undefined} className="underline aria-[current=page]:font-semibold" href={`${paths.ledger}${props.company ? `?company_id=${props.company.id}` : ''}`}>General Ledger</Link>}{props.canTrial && <Link aria-current={props.kind === 'trial' ? 'page' : undefined} className="underline aria-[current=page]:font-semibold" href={`${paths.trial}${props.company ? `?company_id=${props.company.id}` : ''}`}>Trial Balance</Link>}</nav>
        <label className={`${panel} block text-sm font-medium`}>Company<select className={input} value={props.company?.id ?? ''} onChange={event => router.get(paths[props.kind], event.target.value ? { company_id: event.target.value } : {}, { preserveState: false })}><option value="">Select company</option>{props.companies.map(company => <option key={company.id} value={company.id}>{company.code} — {company.name}{company.status !== 'active' ? ' (inactive)' : ''}</option>)}</select></label>
        {props.errors && Object.keys(props.errors).length > 0 && <ul role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">{Object.entries(props.errors).map(([field, message]) => <li key={field}>{field}: {message}</li>)}</ul>}
        {props.company && <FiltersForm key={`${props.kind}:${props.company.id}:${JSON.stringify(props.filters)}`} props={props} />}
        {props.report ? <>
            <p className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">{props.report.coverage_note}</p>
            {props.canExport && props.company && <div className="flex flex-wrap items-center gap-4 text-sm"><a className={button} href={reportUrl(props.kind, props.company.id, props.filters, 1, 'csv')}>Download CSV · all filtered rows</a><a className="underline" href={reportUrl(props.kind, props.company.id, props.filters, 1, 'print')} target="_blank" rel="noopener noreferrer">Print · all filtered rows</a></div>}
            {props.kind === 'ledger' ? <Ledger report={props.report} props={props} /> : <Trial report={props.report} props={props} />}
        </> : <p className={panel}>{props.company ? 'Select an account and apply filters to view its ledger. Account search also uses Apply filters / search.' : 'Select a company to view this report.'}</p>}
    </div></ERPLayout>;
}
