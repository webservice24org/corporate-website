import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { input, ledgerUrl, pageRows, panel, path, reportUrl } from './types';
import type { Issue, Props } from './types';

const issueLabels: Record<string, string> = {
    classification_missing_or_inconsistent: 'Account group and statement role need review',
    nominal_opening_movement_excluded: 'Nominal opening movement excluded from period earnings',
    nonpostable_account_has_activity: 'Nonpostable account has activity',
    cash_binding_identity_review: 'Cash binding identity or currency needs review',
    invalid_cash_classification: 'Cash GL classification needs review',
    cash_binding_missing_or_ambiguous: 'Exactly one concrete cash binding is required',
    binding_gl_not_in_declared_cash_scope: 'Binding GL is outside the declared cash scope',
    cash_scope_undefined: 'Cash scope has not been established',
    active_account_cash_flow_role_missing_or_invalid: 'Account with period activity needs a cash-flow role',
    multi_activity_cash_journal_requires_native_split: 'Mixed activity requires a verified native-event split',
    mixed_cash_noncash_net_requires_native_split: 'Mixed cash/noncash components require a native-event split',
    in_period_cash_opening_requires_cutover_review: 'In-period opening cash requires cutover review',
    cash_snapshot_movement_mismatch: 'Opening/closing cash differs from represented cash movements',
    indirect_operating_bridge_mismatch: 'Indirect operating bridge does not match its control total',
    cash_reconciliation_difference: 'Cash-flow totals do not reconcile to GL cash',
    cash_credit_balance_review: 'Cash-classified GL has a credit balance; review the scope policy',
};

function FiltersForm({ props }: { props: Props }) {
    const [filters, setFilters] = useState(props.filters);
    function submit(event: FormEvent) {
        event.preventDefault();
        if (props.company) router.get(reportUrl(props.company.id, filters), {}, { preserveState: false, preserveScroll: true });
    }
    return <form className={`${panel} flex flex-wrap items-end gap-4 text-sm`} onSubmit={submit}><label>From<input className={input} type="date" required min="1900-01-01" max={props.today ?? undefined} value={filters.from} onChange={event => setFilters({ ...filters, from: event.target.value })} /></label><label>To<input className={input} type="date" required min={filters.from || '1900-01-01'} max={props.today ?? undefined} value={filters.to} onChange={event => setFilters({ ...filters, to: event.target.value })} /></label><button className="rounded-lg bg-indigo-700 px-4 py-2 font-medium text-white">Apply dates</button><Link className="self-center underline" href={`${path}?company_id=${props.company?.id ?? ''}`}>Reset</Link></form>;
}

function PagedTable({ title, headers, rows }: { title: string; headers: string[]; rows: ReactNode[][] }) {
    const [page, setPage] = useState(1); const { current, last, rows: visible } = pageRows(rows, page);
    return <section className={`${panel} overflow-x-auto`}><h2 className="font-semibold">{title} · {rows.length}</h2><table className="mt-3 w-full text-left text-sm"><caption className="sr-only">{title}</caption><thead><tr>{headers.map(header => <th className="whitespace-nowrap p-3" key={header} scope="col">{header}</th>)}</tr></thead><tbody>{visible.map((row, index) => <tr className="border-t border-slate-100 dark:border-slate-700" key={`${current}:${index}`}>{row.map((cell, column) => <td className="p-3 align-top" key={column}>{cell}</td>)}</tr>)}</tbody></table>{!rows.length && <p className="mt-2 text-sm text-slate-500">No entries within the selected scope.</p>}{last > 1 && <nav className="mt-4 flex flex-wrap gap-4 text-sm" aria-label={`${title} pages`}><button type="button" className="underline disabled:opacity-50" disabled={current <= 1} onClick={() => setPage(current - 1)}>Previous</button><span>{current} / {last} · totals cover all rows</span><button type="button" className="underline disabled:opacity-50" disabled={current >= last} onClick={() => setPage(current + 1)}>Next</button></nav>}</section>;
}

function Review({ title, issues, props }: { title: string; issues: Issue[]; props: Props }) {
    const rows = issues.map(issue => [
        <div key="reference"><p className="break-words font-medium">{issue.code ? `${issue.code} — ${issue.name ?? ''}` : 'Report control'}</p>{issue.journal_id !== undefined && <p>Journal #{issue.journal_id}</p>}{issue.binding_id !== undefined && <p>Binding #{issue.binding_id}</p>}{issue.account_id !== undefined && <p>Account #{issue.account_id}</p>}</div>,
        <div key="reason"><p>{issueLabels[issue.issue] ?? issue.issue.replaceAll('_', ' ')}</p>{issue.statement_role !== undefined && <p className="mt-1">Group: {issue.category || 'Missing'} · Role: {issue.statement_role || 'Missing'}</p>}{(issue.period_debit !== undefined || issue.debit !== undefined) && <p className="mt-1 font-mono">Debit {issue.period_debit ?? issue.debit} · Credit {issue.period_credit ?? issue.credit}</p>}{issue.amount !== undefined && <p className="mt-1 font-mono">{issue.amount} {props.report?.currency}</p>}</div>,
        props.canLedger && props.company && issue.account_id !== undefined ? <Link key="link" className="underline" href={ledgerUrl(props.company.id, issue.account_id, props.filters)}>Account ledger</Link> : '—',
    ]);
    return <PagedTable title={title} headers={['Reference', 'Review', 'Details']} rows={rows} />;
}

export default function Index(props: Props) {
    const report = props.report; const scope = `${props.company?.id ?? 0}:${JSON.stringify(props.filters)}`;
    return <ERPLayout><Head title="Cash Flow" /><div className="space-y-5">
        <header><h1 className="text-2xl font-semibold">Cash Flow</h1><p className="mt-1 text-sm text-slate-500">Indirect method, classification readiness and reconciliation to declared GL cash.</p></header>
        <nav className="flex flex-wrap gap-4 text-sm" aria-label="Financial reports"><Link className="font-semibold underline" aria-current="page" href={props.company ? reportUrl(props.company.id, props.filters) : path}>Cash Flow</Link>{props.canProfit && <Link className="underline" href={`/erp/accounting/profit-loss${props.company ? `?company_id=${props.company.id}&from=${props.filters.from}&to=${props.filters.to}` : ''}`}>Profit and Loss</Link>}{props.canBalance && <Link className="underline" href={`/erp/accounting/balance-sheet${props.company ? `?company_id=${props.company.id}&as_of=${props.filters.to}` : ''}`}>Balance Sheet</Link>}{props.canTrial && <Link className="underline" href={`/erp/accounting/trial-balance${props.company ? `?company_id=${props.company.id}&from=${props.filters.from}&to=${props.filters.to}` : ''}`}>Trial Balance</Link>}{props.canLedger && <Link className="underline" href={`/erp/accounting/general-ledger${props.company ? `?company_id=${props.company.id}&from=${props.filters.from}&to=${props.filters.to}` : ''}`}>General Ledger</Link>}</nav>
        <label className={`${panel} block text-sm font-medium`}>Company<select className={input} value={props.company?.id ?? ''} onChange={event => router.get(path, event.target.value ? { company_id: event.target.value } : {}, { preserveState: false })}><option value="">Select company</option>{props.companies.map(company => <option key={company.id} value={company.id}>{company.code} — {company.name}{company.status !== 'active' ? ' (inactive)' : ''}</option>)}</select></label>
        {props.errors && Object.keys(props.errors).length > 0 && <ul role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">{Object.entries(props.errors).map(([field, value]) => <li key={field}>{field}: {value}</li>)}</ul>}
        {props.company && <FiltersForm key={scope} props={props} />}
        {report ? <>
            <section role="status" className={`${panel} ${report.complete ? 'border-emerald-400' : 'border-amber-400'}`}><h2 className="font-semibold">{report.complete ? 'Checks pass within the declared GL scope' : 'Provisional — confirmed cash flows withheld'}</h2><p className="mt-2 text-sm">{report.coverage_note}</p><p className="mt-2 text-sm">Current account roles define presentation. A zero cash difference does not resolve missing classifications, ambiguous mixed activity or native-source completeness.</p></section>
            {props.canExport && props.company && <div className="flex flex-wrap items-center gap-4 text-sm"><a className="rounded-lg bg-indigo-700 px-4 py-2 font-medium text-white" href={reportUrl(props.company.id, props.filters, 'csv')}>Download {report.complete ? '' : 'provisional '}CSV · all rows</a><a className="underline" href={reportUrl(props.company.id, props.filters, 'print')} target="_blank" rel="noopener noreferrer">Print {report.complete ? '' : 'provisional '}report · all rows</a></div>}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{[['Operating cash', report.provisional_flows.operating], ['Investing cash', report.provisional_flows.investing], ['Financing cash', report.provisional_flows.financing], ['Net activity change', report.provisional_flows.net_change]].map(([label, value]) => <div className={panel} key={label}><p className="text-sm text-slate-500">{label} · {report.currency}{!report.complete ? ' · provisional' : ''}</p><p className="mt-2 break-all font-mono text-xl font-semibold">{value}</p></div>)}</div>
            <section className={`${panel} space-y-3`} aria-label="Indirect operating bridge"><h2 className="font-semibold">Indirect operating bridge{!report.complete ? ' · provisional' : ''}</h2><dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">{[['Period result', report.indirect_bridge.period_result], ['Operating balance adjustments', report.indirect_bridge.operating_balance_adjustments], ['Other noncash adjustments', report.indirect_bridge.other_noncash_adjustments], ['Earnings reclassification', report.indirect_bridge.earnings_reclassification], ['Operating cash', report.indirect_bridge.operating_cash]].map(([label, amount]) => <div key={label}><dt className="text-slate-500">{label}</dt><dd className="mt-1 break-all font-mono text-lg">{amount}</dd></div>)}</dl></section>
            <section className={`${panel} space-y-3`} aria-label="Cash reconciliation"><h2 className="font-semibold">Cash reconciliation · {report.currency}</h2><dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">{[['Beginning GL cash', report.beginning_cash], ['Closing GL cash', report.closing_cash], ['In-period opening cash', report.opening_cash_movements], ['Economic cash movement', report.economic_cash_movement], ['Unallocated counterpart net', report.unallocated_cash_counterparts], ['Reconciliation difference', report.reconciliation_difference], ['GL snapshot difference', report.snapshot_difference], ['Indirect operating difference', report.indirect_operating_difference]].map(([label, amount]) => <div key={label}><dt className="text-slate-500">{label}</dt><dd className="mt-1 break-all font-mono text-lg">{amount}</dd></div>)}</dl><p className="text-sm text-slate-500">Closing − beginning − in-period opening movements − net activity change = reconciliation difference. Cash-only internal transfers excluded: {report.cash_transfer_count}.</p></section>
            <Review key={`issues:${scope}`} title="Readiness review" issues={report.issues} props={props} />
            {props.canAccounts && report.issues.length > 0 && <Link className="inline-block text-sm underline" href={`/erp/accounting/accounts?company_id=${props.company?.id ?? ''}`}>Open Chart of Accounts for authorized classification review</Link>}
            <Review key={`warnings:${scope}`} title="Review notices" issues={report.warnings} props={props} />
            <PagedTable key={`cash-accounts:${scope}`} title="Declared cash accounts" headers={['Account', 'Status', 'Beginning', 'Closing']} rows={report.cash_accounts.map(account => [props.canLedger && props.company ? <Link key="account" className="underline" href={ledgerUrl(props.company.id, account.account_id, props.filters)}>{account.code} — {account.name}</Link> : `${account.code} — ${account.name}`, account.status, account.beginning, account.closing])} />
            <PagedTable key={`noncash:${scope}`} title="Noncash journal disclosures — not cash flows" headers={['Date', 'Journal', 'Earnings movement', 'Operating balance change', 'Investing balance change', 'Financing balance change']} rows={report.noncash_journals.map(journal => [journal.date, `${journal.number} (#${journal.journal_id})`, journal.profit_movement, journal.operating_balance_adjustment, journal.investing_balance_change, journal.financing_balance_change])} />
            <PagedTable key={`cash-journals:${scope}`} title="Cash journal controls" headers={['Date', 'Journal', 'Actual cash movement', 'Operating counterpart', 'Investing counterpart', 'Financing counterpart']} rows={report.cash_journals.map(journal => [journal.date, `${journal.number} (#${journal.journal_id})`, journal.cash_movement, journal.operating, journal.investing, journal.financing])} />
            <p className="text-sm text-slate-500">Mixed activity needs a verified native split; changing a report filter cannot approve one. In-period opening movements require cutover review. All cards and exports cover the full applied scope, regardless of disclosure page.</p>
        </> : <p className={panel}>Select a company to view Cash Flow and readiness.</p>}
    </div></ERPLayout>;
}
