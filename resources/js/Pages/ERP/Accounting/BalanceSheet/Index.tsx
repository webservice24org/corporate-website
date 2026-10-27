import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { input, ledgerUrl, panel, path, reportUrl } from './types';
import type { Issue, Props } from './types';

const labels: Record<string, string> = {
    fiscal_year_state_unsupported: 'Fiscal-year state requires calendar review',
    fiscal_year_missing_or_ambiguous: 'Exactly one company fiscal year must cover this date',
    classification_missing_or_inconsistent: 'Account group and statement role need review',
    nonpostable_account_has_balance_or_activity: 'Nonpostable account has a balance or activity',
    nominal_opening_requires_cutover_review: 'Nominal opening movement requires cutover review',
    prior_year_nominal_balance_unclosed: 'Prior-year earnings remain in a nominal account',
    earnings_bridge_does_not_reconcile: 'Earnings bridge does not reconcile to nominal balances',
    balance_sheet_equation_difference: 'Assets differ from liabilities and equity',
    closed_year_has_nominal_residual: 'Closed fiscal year still has a nominal GL residual',
    net_contra_balance: 'Signed balance is opposite this section’s usual direction',
};

function DateForm({ props }: { props: Props }) {
    const [asOf, setAsOf] = useState(props.asOf);
    function submit(event: FormEvent) {
        event.preventDefault();
        if (props.company) router.get(reportUrl(props.company.id, asOf), {}, { preserveState: false, preserveScroll: true });
    }
    return <form className={`${panel} flex flex-wrap items-end gap-4 text-sm`} onSubmit={submit}><label>As of<input className={input} type="date" required min="1900-01-01" max={props.today ?? undefined} value={asOf} onChange={event => setAsOf(event.target.value)} /></label><button className="rounded-lg bg-indigo-700 px-4 py-2 font-medium text-white">Apply date</button><Link className="self-center underline" href={`${path}?company_id=${props.company?.id ?? ''}`}>Reset</Link></form>;
}

function Review({ title, issues, props }: { title: string; issues: Issue[]; props: Props }) {
    const [page, setPage] = useState(1);
    const last = Math.max(1, Math.ceil(issues.length / 25)); const current = Math.min(page, last);
    if (!issues.length) return null;
    return <section className={`${panel} border-amber-400`} aria-label={title}><h2 className="font-semibold">{title} · {issues.length}</h2><ul className="mt-3 space-y-3 text-sm">{issues.slice((current - 1) * 25, current * 25).map((issue, index) => <li className="rounded-lg bg-amber-50 p-3 text-amber-950" key={`${issue.account_id ?? 'report'}:${issue.issue}:${index}`}>
        {issue.code !== undefined && <p className="break-words font-medium">{issue.code} — {issue.name}</p>}
        <p>{labels[issue.issue] ?? issue.issue.replaceAll('_', ' ')}</p>
        {issue.statement_role !== undefined && <p className="mt-1">Group: {issue.category || 'Missing'} · Role: {issue.statement_role || 'Missing'}</p>}
        {issue.debit !== undefined && <p className="mt-1 font-mono">Debit {issue.debit} · Credit {issue.credit}</p>}
        {issue.amount !== undefined && <p className="mt-1 font-mono">{issue.amount} {props.report?.currency}</p>}
        {props.canLedger && props.company && issue.account_id !== undefined && <Link className="mt-2 inline-block underline" href={ledgerUrl(props.company.id, issue.account_id, props.asOf)}>Review account ledger</Link>}
    </li>)}</ul>{last > 1 && <nav className="mt-4 flex gap-4 text-sm" aria-label={`${title} pages`}><button type="button" className="underline disabled:opacity-50" disabled={current <= 1} onClick={() => setPage(current - 1)}>Previous</button><span>{current} / {last}</span><button type="button" className="underline disabled:opacity-50" disabled={current >= last} onClick={() => setPage(current + 1)}>Next</button></nav>}</section>;
}

export default function Index(props: Props) {
    const report = props.report;
    const from = props.fiscalYear?.starts_on ?? '1900-01-01';
    return <ERPLayout><Head title="Balance Sheet" /><div className="space-y-5">
        <header><h1 className="text-2xl font-semibold">Balance Sheet</h1><p className="mt-1 text-sm text-slate-500">Dated posted balances and an explicit earnings bridge in the company currency.</p></header>
        <nav className="flex flex-wrap gap-4 text-sm" aria-label="Financial reports">{props.canCashFlow && <Link className="underline" href={`/erp/accounting/cash-flow${props.company ? `?company_id=${props.company.id}&from=${from}&to=${props.asOf}` : ''}`}>Cash Flow</Link>}<Link className="font-semibold underline" href={props.company ? reportUrl(props.company.id, props.asOf) : path} aria-current="page">Balance Sheet</Link>{props.canTrial && <Link className="underline" href={`/erp/accounting/trial-balance${props.company ? `?company_id=${props.company.id}&from=${from}&to=${props.asOf}` : ''}`}>Trial Balance</Link>}{props.canProfit && <Link className="underline" href={`/erp/accounting/profit-loss${props.company ? `?company_id=${props.company.id}${props.fiscalYear ? `&from=${props.fiscalYear.starts_on}&to=${props.asOf}` : ''}` : ''}`}>{props.fiscalYear ? 'Fiscal-year Profit and Loss' : 'Profit and Loss'}</Link>}</nav>
        <label className={`${panel} block text-sm font-medium`}>Company<select className={input} value={props.company?.id ?? ''} onChange={event => router.get(path, event.target.value ? { company_id: event.target.value } : {}, { preserveState: false })}><option value="">Select company</option>{props.companies.map(company => <option key={company.id} value={company.id}>{company.code} — {company.name}{company.status !== 'active' ? ' (inactive)' : ''}</option>)}</select></label>
        {props.errors && Object.keys(props.errors).length > 0 && <ul role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">{Object.entries(props.errors).map(([field, value]) => <li key={field}>{field}: {value}</li>)}</ul>}
        {props.company && <DateForm key={`${props.company.id}:${props.asOf}`} props={props} />}
        {report ? <>
            <section role="status" className={`${panel} ${report.complete ? 'border-emerald-400' : 'border-amber-400'}`}><h2 className="font-semibold">{report.complete ? 'Balance Sheet classifications and equation pass the report checks' : 'Review required — confirmed totals withheld'}</h2><p className="mt-2 text-sm">{report.coverage_note}</p><p className="mt-2 text-sm">{props.fiscalYear ? `Fiscal year ${props.fiscalYear.code}: ${props.fiscalYear.starts_on} to ${props.fiscalYear.ends_on} (${props.fiscalYear.state}).` : `Fiscal year unavailable: ${report.fiscal_year_matches === 0 ? 'no matching year' : 'overlapping years'}. No calendar-year assumption is made.`}</p><p className="mt-2 text-sm">Current account classifications determine presentation. A zero equation difference alone does not certify complete native-source evidence.</p></section>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{[['Assets', report.totals.assets], ['Liabilities', report.totals.liabilities], ['Equity including unclosed earnings', report.totals.equity_with_earnings], ['Equation difference', report.difference]].map(([label, value]) => <div className={panel} key={label}><p className="text-sm text-slate-500">{label} · {report.currency}{!report.complete ? ' · provisional' : ''}</p><p className="mt-2 break-all font-mono text-xl font-semibold">{value}</p></div>)}</div>
            <p className="text-sm text-slate-600 dark:text-slate-300">Assets {report.totals.assets} − liabilities and equity {report.totals.liabilities_and_equity} = {report.difference} {report.currency}. Signed contra balances reduce their sections.</p>
            <section className={`${panel} space-y-4`} aria-label="Earnings bridge"><h2 className="font-semibold">Earnings bridge — added once</h2><dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">{[['Posted equity including retained earnings', report.totals.posted_equity], ['Prior-year nominal balances still unclosed', report.earnings.prior_year_unclosed ?? 'Unavailable'], ['Current-year nominal opening movements', report.earnings.current_year_nominal_opening ?? 'Unavailable'], ['Current-year earnings remaining in nominal GL', report.earnings.current_year_unclosed_result ?? 'Unavailable'], ['Total nominal residual added to posted equity', report.earnings.residual_total], ['Bridge difference', report.earnings.bridge_difference]].map(([label, value]) => <div key={label}><dt className="text-slate-500">{label}</dt><dd className="mt-1 break-all font-mono text-lg">{value}</dd></div>)}</dl><p className="text-sm text-slate-500">Posted retained earnings are already in equity. Only balances still in revenue/expense accounts are added by this bridge. This read does not post closing or retained-earnings journals.</p></section>
            <Review key={`issues:${props.company?.id}:${props.asOf}`} title="Balance Sheet review" issues={report.issues} props={props} />
            {props.canAccounts && report.issues.length > 0 && <Link className="inline-block text-sm underline" href={`/erp/accounting/accounts?company_id=${props.company?.id ?? ''}`}>Open Chart of Accounts for authorized review</Link>}
            {report.sections.map(section => <section className={`${panel} overflow-x-auto`} key={section.role}><h2 className="font-semibold">{section.label} · <span className="font-mono">{section.amount} {report.currency}</span>{!report.complete && <span className="ml-2 text-xs text-amber-700 dark:text-amber-300">Provisional</span>}</h2><table className="mt-3 w-full text-left text-sm"><caption className="sr-only">{section.label} account balances at {props.asOf}</caption><thead><tr>{['Account', 'Debit balance', 'Credit balance', 'Section amount'].map(label => <th className="whitespace-nowrap p-3" scope="col" key={label}>{label}</th>)}</tr></thead><tbody>{section.accounts.map(account => <tr className="border-t border-slate-100 dark:border-slate-700" key={account.account_id}><th className="min-w-56 p-3 font-medium" scope="row">{props.canLedger && props.company ? <Link className="text-indigo-700 underline dark:text-indigo-300" href={ledgerUrl(props.company.id, account.account_id, props.asOf)}>{account.code} — {account.name}</Link> : `${account.code} — ${account.name}`}{account.status !== 'active' && <span className="ml-2 text-xs text-slate-500">Inactive</span>}</th>{[account.debit, account.credit, account.amount].map((value, index) => <td className="whitespace-nowrap p-3 font-mono" key={index}>{value}</td>)}</tr>)}</tbody></table>{!section.accounts.length && <p className="mt-2 text-sm text-slate-500">No accounts classified in this section.</p>}</section>)}
            <Review key={`warnings:${props.company?.id}:${props.asOf}`} title="Contra balance notices" issues={report.warnings} props={props} />
        </> : <p className={panel}>Select a company to view its Balance Sheet.</p>}
    </div></ERPLayout>;
}
