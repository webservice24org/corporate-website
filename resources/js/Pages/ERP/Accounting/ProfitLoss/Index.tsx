import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { input, ledgerUrl, panel, path, reportUrl } from './types';
import type { Issue, Props } from './types';

const issueLabels: Record<string, string> = {
    classification_missing_or_inconsistent: 'Account group and statement role need review',
    nominal_opening_movement_excluded: 'Opening movement excluded from period income',
    nonpostable_account_has_activity: 'Nonpostable account has period activity',
    net_contra_movement: 'Net movement reverses this section’s usual direction',
};

function FiltersForm({ props }: { props: Props }) {
    const [filters, setFilters] = useState(props.filters);
    function submit(event: FormEvent) {
        event.preventDefault();
        if (props.company) router.get(reportUrl(props.company.id, filters), {}, { preserveState: false, preserveScroll: true });
    }
    return <form onSubmit={submit} className={`${panel} grid items-end gap-4 text-sm sm:grid-cols-3`}>
        <label>From<input className={input} type="date" required min="1900-01-01" max={props.today ?? undefined} value={filters.from} onChange={event => setFilters({ ...filters, from: event.target.value })} /></label>
        <label>To<input className={input} type="date" required min={filters.from || '1900-01-01'} max={props.today ?? undefined} value={filters.to} onChange={event => setFilters({ ...filters, to: event.target.value })} /></label>
        <div className="flex flex-wrap items-center gap-4"><button className="rounded-lg bg-indigo-700 px-4 py-2 font-medium text-white">Apply dates</button><Link className="underline" href={`${path}?company_id=${props.company?.id ?? ''}`}>Reset</Link></div>
    </form>;
}

function Review({ title, issues, props }: { title: string; issues: Issue[]; props: Props }) {
    const [page, setPage] = useState(1);
    const last = Math.max(1, Math.ceil(issues.length / 25));
    const current = Math.min(page, last);
    if (!issues.length) return null;
    return <section className={`${panel} border-amber-400`} aria-label={title}>
        <h2 className="font-semibold">{title} · {issues.length}</h2>
        <ul className="mt-3 space-y-3 text-sm">{issues.slice((current - 1) * 25, current * 25).map((issue, index) => <li className="rounded-lg bg-amber-50 p-3 text-amber-950" key={`${issue.account_id}:${issue.issue}:${index}`}>
            <p className="break-words font-medium">{issue.code} — {issue.name}</p>
            <p className="mt-1">{issueLabels[issue.issue] ?? issue.issue.replaceAll('_', ' ')}</p>
            {issue.statement_role !== undefined && <p className="mt-1">Group: {issue.category || 'Missing'} · Role: {issue.statement_role || 'Missing'}</p>}
            {issue.period_debit !== undefined && <p className="mt-1 font-mono">Debit {issue.period_debit} · Credit {issue.period_credit}</p>}
            {issue.amount !== undefined && <p className="mt-1 font-mono">{issue.amount} {props.report?.currency}</p>}
            {props.canLedger && props.company && <Link className="mt-2 inline-block underline" href={ledgerUrl(props.company.id, issue.account_id, props.filters)}>Review General Ledger</Link>}
        </li>)}</ul>
        {last > 1 && <nav className="mt-4 flex flex-wrap gap-4 text-sm" aria-label={`${title} pages`}><button type="button" className="underline disabled:opacity-50" disabled={current <= 1} onClick={() => setPage(current - 1)}>Previous</button><span>{current} / {last}</span><button type="button" className="underline disabled:opacity-50" disabled={current >= last} onClick={() => setPage(current + 1)}>Next</button></nav>}
    </section>;
}

export default function Index(props: Props) {
    const report = props.report;
    return <ERPLayout><Head title="Profit and Loss" /><div className="space-y-5">
        <header><h1 className="text-2xl font-semibold">Profit and Loss</h1><p className="mt-1 text-sm text-slate-500">Period movements in the company currency, using current account classifications.</p></header>
        <nav className="flex flex-wrap gap-4 text-sm" aria-label="Financial reports">{props.canCashFlow && <Link className="underline" href={`/erp/accounting/cash-flow${props.company ? `?company_id=${props.company.id}&from=${props.filters.from}&to=${props.filters.to}` : ''}`}>Cash Flow</Link>}{props.canBalance && <Link className="underline" href={`/erp/accounting/balance-sheet${props.company ? `?company_id=${props.company.id}&as_of=${props.filters.to}` : ''}`}>Balance Sheet</Link>}<Link className="font-semibold underline" href={props.company ? reportUrl(props.company.id, props.filters) : path} aria-current="page">Profit and Loss</Link>{props.canLedger && <Link className="underline" href={`/erp/accounting/general-ledger${props.company ? `?company_id=${props.company.id}&from=${props.filters.from}&to=${props.filters.to}` : ''}`}>General Ledger</Link>}{props.canTrial && <Link className="underline" href={`/erp/accounting/trial-balance${props.company ? `?company_id=${props.company.id}&from=${props.filters.from}&to=${props.filters.to}` : ''}`}>Trial Balance</Link>}</nav>
        <label className={`${panel} block text-sm font-medium`}>Company<select className={input} value={props.company?.id ?? ''} onChange={event => router.get(path, event.target.value ? { company_id: event.target.value } : {}, { preserveState: false })}><option value="">Select company</option>{props.companies.map(company => <option key={company.id} value={company.id}>{company.code} — {company.name}{company.status !== 'active' ? ' (inactive)' : ''}</option>)}</select></label>
        {props.errors && Object.keys(props.errors).length > 0 && <ul role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">{Object.entries(props.errors).map(([field, value]) => <li key={field}>{field}: {value}</li>)}</ul>}
        {props.company && <FiltersForm key={`${props.company.id}:${JSON.stringify(props.filters)}`} props={props} />}
        {report ? <>
            <section role="status" className={`${panel} ${report.classification_complete ? 'border-emerald-400' : 'border-amber-400'}`}><h2 className="font-semibold">{report.classification_complete ? 'Account classifications pass the report checks' : 'Classification review required — confirmed result withheld'}</h2><p className="mt-2 text-sm">{report.coverage_note}</p><p className="mt-2 text-sm">Opening movements are excluded from income. Current account group/role changes can restate this report’s presentation. This report does not calculate statutory tax or certify after-tax profit.</p></section>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{[['Net operating revenue', report.net_revenue], ['Gross profit', report.gross_profit], ['Operating result', report.operating_result], ['Period result', report.period_result ?? 'Review required']].map(([label, value]) => <div className={panel} key={label}><p className="text-sm text-slate-500">{label} · {report.currency}{!report.classification_complete && label !== 'Period result' ? ' · provisional' : ''}</p><p className="mt-2 break-all font-mono text-xl font-semibold">{value}</p></div>)}</div>
            {!report.classification_complete && <p className="text-sm text-amber-800 dark:text-amber-300">Provisional classified subtotal: {report.provisional_result} {report.currency}. Resolve all review issues before relying on a complete period result.</p>}
            <Review key={`issues:${props.company?.id}:${JSON.stringify(props.filters)}`} title="Classification review" issues={report.issues} props={props} />
            {props.canAccounts && report.issues.length > 0 && <Link className="inline-block text-sm underline" href={`/erp/accounting/accounts?company_id=${props.company?.id ?? ''}`}>Open Chart of Accounts for authorized review</Link>}
            {report.sections.map(section => <section className={`${panel} overflow-x-auto`} key={section.role}>
                <h2 className="font-semibold">{section.label} · <span className="font-mono">{section.amount} {report.currency}</span>{!report.classification_complete && <span className="ml-2 text-xs text-amber-700 dark:text-amber-300">Provisional</span>}</h2>
                <table className="mt-3 w-full text-left text-sm"><caption className="sr-only">{section.label} by account</caption><thead><tr>{['Account', 'Debit movement', 'Credit movement', 'Section amount'].map(label => <th className="whitespace-nowrap p-3" key={label} scope="col">{label}</th>)}</tr></thead><tbody>{section.accounts.map(account => <tr className="border-t border-slate-100 dark:border-slate-700" key={account.account_id}><th className="min-w-56 p-3 font-medium" scope="row">{props.canLedger && props.company ? <Link className="text-indigo-700 underline dark:text-indigo-300" href={ledgerUrl(props.company.id, account.account_id, props.filters)}>{account.code} — {account.name}</Link> : `${account.code} — ${account.name}`}{account.status !== 'active' && <span className="ml-2 text-xs text-slate-500">Inactive</span>}</th>{[account.debit, account.credit, account.amount].map((value, index) => <td className="whitespace-nowrap p-3 font-mono" key={index}>{value}</td>)}</tr>)}</tbody></table>
                {!section.accounts.length && <p className="mt-2 text-sm text-slate-500">No accounts classified in this section.</p>}
            </section>)}
            <Review key={`warnings:${props.company?.id}:${JSON.stringify(props.filters)}`} title="Contra movement notices" issues={report.warnings} props={props} />
            <p className="text-sm text-slate-500">Revenue and other income use credit minus debit. Allowances and expenses use debit minus credit. Negative amounts retain reversals and contra activity. Excluded nominal opening debit: {report.excluded_nominal_opening_debit}; credit: {report.excluded_nominal_opening_credit}. A General Ledger drill-down also shows opening movements.</p>
        </> : <p className={panel}>Select a company to view Profit and Loss.</p>}
    </div></ERPLayout>;
}
