import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { amountLabel, bucketLabels, input, ledgerUrl, pageRows, panel, paths, reportUrl, titles } from './types';
import type { Bucket, Props } from './types';

const issueLabels: Record<string, string> = {
    historical_due_terms_unverified: 'Historical due-date terms are not verified',
    due_date_missing: 'Due date is missing; no invoice-date substitution was made',
    document_recognition_or_legacy_coverage_unverified: 'Document balance or legacy coverage remains unknown',
    invoice_evidence_or_legacy_review: 'Invoice evidence or legacy coverage needs review',
    credit_note_evidence_review: 'Credit-note evidence needs review',
    receipt_operation_family_review: 'Receipt operation family needs review',
    receipt_allocation_evidence_review: 'Receipt allocation evidence needs review',
    credit_transfer_evidence_review: 'Credit transfer evidence needs review',
    orphan_or_uncollected_admission: 'Source admission has no collected native record',
    control_mapping_missing: 'Control mapping is missing',
    control_role_ambiguous: 'Historical control role is ambiguous',
    unassigned_control_line: 'Control line has no valid assigned party',
    unsupported_control_membership: 'GL control membership is outside verified source coverage',
    opening_control_coverage_not_integrated: 'Opening control coverage is not integrated into live aging',
    source_gl_membership_mismatch: 'Source recipes and GL line membership differ',
    document_position_control_mismatch: 'Signed document position differs from its source control',
    negative_unapplied_funds_or_advances: 'Unapplied funds/advance control is negative',
    control_mapping_account_not_owned: 'Control mapping references an account outside the company',
};

function DateForm({ props }: { props: Props }) {
    const [asOf, setAsOf] = useState(props.asOf);
    function submit(event: FormEvent) { event.preventDefault(); if (props.company) router.get(reportUrl(props.kind, props.company.id, asOf), {}, { preserveState: false, preserveScroll: true }); }
    return <form className={`${panel} flex flex-wrap items-end gap-4 text-sm`} onSubmit={submit}><label>As of<input className={input} type="date" required min="1900-01-01" max={props.today ?? undefined} value={asOf} onChange={event => setAsOf(event.target.value)} /></label><button className="rounded-lg bg-indigo-700 px-4 py-2 font-medium text-white">Apply date</button><Link className="self-center underline" href={`${paths[props.kind]}?company_id=${props.company?.id ?? ''}`}>Reset</Link></form>;
}

function PagedTable({ title, headers, rows }: { title: string; headers: string[]; rows: ReactNode[][] }) {
    const [page, setPage] = useState(1); const { current, last, rows: visible } = pageRows(rows, page);
    return <section className={`${panel} overflow-x-auto`}><h2 className="font-semibold">{title} · {rows.length}</h2><table className="mt-3 w-full text-left text-sm"><caption className="sr-only">{title}</caption><thead><tr>{headers.map(header => <th className="whitespace-nowrap p-3" scope="col" key={header}>{header}</th>)}</tr></thead><tbody>{visible.map((row, index) => <tr className="border-t border-slate-100 dark:border-slate-700" key={`${current}:${index}`}>{row.map((cell, column) => <td className="p-3 align-top" key={column}>{cell}</td>)}</tr>)}</tbody></table>{!rows.length && <p className="mt-2 text-sm text-slate-500">No entries within the represented scope.</p>}{last > 1 && <nav className="mt-4 flex flex-wrap gap-4 text-sm" aria-label={`${title} pages`}><button type="button" className="underline disabled:opacity-50" disabled={current <= 1} onClick={() => setPage(current - 1)}>Previous</button><span>{current} / {last} · totals cover all rows</span><button type="button" className="underline disabled:opacity-50" disabled={current >= last} onClick={() => setPage(current + 1)}>Next</button></nav>}</section>;
}

export default function Index(props: Props) {
    const report = props.report; const title = titles[props.kind]; const scope = `${props.kind}:${props.company?.id ?? 0}:${props.asOf}`;
    const funds = props.kind === 'ar' ? 'Unapplied customer funds' : 'Supplier advances';
    return <ERPLayout><Head title={title} /><div className="space-y-5">
        <header><h1 className="text-2xl font-semibold">{title}</h1><p className="mt-1 text-sm text-slate-500">Dated live trade documents, due-date buckets and source-to-GL control review.</p></header>
        <nav className="flex flex-wrap gap-4 text-sm" aria-label="Aging reports">{props.canAr && <Link className="underline aria-[current=page]:font-semibold" aria-current={props.kind === 'ar' ? 'page' : undefined} href={props.company ? reportUrl('ar', props.company.id, props.asOf) : paths.ar}>AR Aging</Link>}{props.canAp && <Link className="underline aria-[current=page]:font-semibold" aria-current={props.kind === 'ap' ? 'page' : undefined} href={props.company ? reportUrl('ap', props.company.id, props.asOf) : paths.ap}>AP Aging</Link>}{props.canTrial && <Link className="underline" href={`/erp/accounting/trial-balance${props.company ? `?company_id=${props.company.id}&from=1900-01-01&to=${props.asOf}` : ''}`}>Trial Balance</Link>}</nav>
        <label className={`${panel} block text-sm font-medium`}>Company<select className={input} value={props.company?.id ?? ''} onChange={event => router.get(paths[props.kind], event.target.value ? { company_id: event.target.value } : {}, { preserveState: false })}><option value="">Select company</option>{props.companies.map(company => <option key={company.id} value={company.id}>{company.code} — {company.name}{company.status !== 'active' ? ' (inactive)' : ''}</option>)}</select></label>
        {props.errors && Object.keys(props.errors).length > 0 && <ul role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">{Object.entries(props.errors).map(([field, message]) => <li key={field}>{field}: {message}</li>)}</ul>}
        {props.company && <DateForm key={scope} props={props} />}
        {report ? <>
            <section role="status" className={`${panel} ${report.complete ? 'border-emerald-400' : 'border-amber-400'}`}><h2 className="font-semibold">{report.complete ? 'Checks pass within the represented live trade scope' : 'Provisional — confirmed aging totals withheld'}</h2><p className="mt-2 text-sm">Legacy opening coverage is not integrated. Unknown balances remain unknown. Expense and payroll payables are separate cohorts. Zero control differences alone do not establish complete source coverage.</p>{props.kind === 'ap' && <p className="mt-2 text-sm">Supplier due dates use current native terms. Historical buckets are provisional when original terms cannot be verified.</p>}</section>
            {props.canExport && props.company && <div className="flex flex-wrap items-center gap-4 text-sm"><a className="rounded-lg bg-indigo-700 px-4 py-2 font-medium text-white" href={reportUrl(props.kind, props.company.id, props.asOf, 'csv')}>Download {report.complete ? '' : 'provisional '}CSV · all rows</a><a className="underline" href={reportUrl(props.kind, props.company.id, props.asOf, 'print')} target="_blank" rel="noopener noreferrer">Print {report.complete ? '' : 'provisional '}report · all rows</a></div>}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{[['Positive open documents', report.totals.open_documents], ['Document credits — separate', report.totals.document_credits], [funds, report.reconciliation.separate_unapplied_or_advance_balance], ['Known signed trade position', report.totals.signed_documents]].map(([label, amount]) => <div className={panel} key={label}><p className="text-sm text-slate-500">{label} · {report.currency}{!report.complete ? ' · provisional' : ''}</p><p className="mt-2 break-all font-mono text-xl font-semibold">{amount}</p></div>)}</div>
            <p className="text-sm text-slate-600 dark:text-slate-300">Unknown document positions: {report.totals.unknown_documents}. Credits and funds/advances are not automatically netted against overdue documents.</p>
            <section className={panel}><h2 className="font-semibold">Due-date buckets · {report.currency}{!report.complete ? ' · provisional' : ''}</h2><dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{(Object.keys(bucketLabels) as Bucket[]).map(bucket => <div key={bucket}><dt className="text-sm text-slate-500">{bucketLabels[bucket]}</dt><dd className="mt-1 break-all font-mono text-lg">{report.totals.buckets[bucket]}</dd></div>)}</dl></section>
            <section className={`${panel} overflow-x-auto`}><h2 className="font-semibold">Control reconciliation</h2><p className="mt-2 text-sm">{report.reconciliation.reconciled ? 'Represented source recipes match GL control membership.' : 'Control membership or amount differences require review.'} Signed-document difference: {report.reconciliation.document_difference} {report.currency}.</p><table className="mt-3 w-full text-left text-sm"><caption className="sr-only">Native source to posted GL controls</caption><thead><tr>{['Control', 'Source', 'Posted GL', 'Difference'].map(label => <th className="whitespace-nowrap p-3" scope="col" key={label}>{label}</th>)}</tr></thead><tbody>{Object.keys(report.reconciliation.source).map(role => <tr className="border-t border-slate-100 dark:border-slate-700" key={role}><th className="p-3 font-medium" scope="row">{role.replaceAll('_', ' ')}</th><td className="p-3 font-mono">{report.reconciliation.source[role]}</td><td className="p-3 font-mono">{report.reconciliation.gl[role]}</td><td className="p-3 font-mono">{report.reconciliation.difference[role]}</td></tr>)}</tbody></table></section>
            <PagedTable key={`documents:${scope}`} title={props.kind === 'ar' ? 'Invoice aging' : 'Trade bill aging'} headers={['Document', 'Party', 'Recognized', 'Due', 'Terms evidence', 'Overdue days', 'Bucket', 'Outstanding', 'State']} rows={report.rows.map(row => [row.native_url ? <Link key="native" className="underline" href={row.native_url}>{row.number}</Link> : row.number, `${row.party_label} (#${row.party_id})`, row.recognized_on ?? 'Unknown', row.due_date ?? 'Unknown', row.due_basis === 'admitted' ? 'Admitted terms' : row.due_basis === 'current_native_terms' ? (props.today && props.asOf < props.today ? 'Current terms — historical review' : 'Current native terms') : 'Unknown', row.days_overdue === null ? 'Unknown' : row.days_overdue <= 0 ? 'Not overdue' : String(row.days_overdue), row.bucket ? bucketLabels[row.bucket] : 'Not aged', <span key="amount" className="whitespace-nowrap font-mono">{amountLabel(row.outstanding)}</span>, row.balance_kind.replaceAll('_', ' ')])} />
            <PagedTable key={`coverage:${scope}`} title="Coverage review" headers={['Reference', 'Issue', 'Amount', 'Details']} rows={report.issues.map(issue => [<div key="reference">{issue.document_number && <p className="font-medium">{issue.document_number}</p>}{issue.document_id !== undefined && <p>Document #{issue.document_id}</p>}{issue.source_kind && <p>{issue.source_kind.replaceAll('_', ' ')} #{issue.source_id}</p>}{issue.journal_id !== undefined && <p>Journal #{issue.journal_id}</p>}{issue.account_id !== undefined && <p>Account #{issue.account_id}</p>}</div>, issueLabels[issue.issue] ?? issue.issue.replaceAll('_', ' '), issue.amount ?? '—', props.canLedger && props.company && issue.account_id !== undefined ? <Link key="ledger" className="underline" href={ledgerUrl(props.company.id, issue.account_id, props.asOf)}>Account ledger</Link> : '—'])} />
            <p className="text-sm text-slate-500">Due today is current; overdue starts the following calendar day. Missing due dates stay in the unknown-due bucket. Totals and exports cover the entire applied company/date scope, independent of table pages.</p>
        </> : <p className={panel}>Select a company to view dated aging and coverage.</p>}
    </div></ERPLayout>;
}
