import { Head, Link, router } from '@inertiajs/react';

import ERPLayout from '@/Layouts/erp-layout';
import { Coverage, Filters, Summary } from './Shared';
import { bookUrl, input, money, panel, paths, titles } from './types';
import type { Kind, Props } from './types';

export default function Book(props: Props) {
    const report = props.report;

    return (
        <ERPLayout>
            <Head title={titles[props.kind]} />
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{titles[props.kind]}</h1>
                        <p className="mt-2 text-sm text-slate-500">Dated movements from posted GL lines, with supported source verification shown separately.</p>
                    </div>
                    {props.canReadiness && props.company && (
                        <Link className="text-sm underline" href={`/erp/accounting/cash-bank?company_id=${props.company.id}`}>Cash and bank readiness</Link>
                    )}
                </header>

                <nav aria-label="Finance books" className="flex gap-3 text-sm">
                    {(['cash', 'bank'] as Kind[]).map((kind) => (
                        <Link
                            key={kind}
                            aria-current={props.kind === kind ? 'page' : undefined}
                            className={`rounded-lg border px-4 py-2 ${props.kind === kind ? 'border-slate-900 font-semibold dark:border-white' : 'border-slate-300'}`}
                            href={props.company ? `${paths[kind]}?company_id=${props.company.id}` : paths[kind]}
                        >
                            {titles[kind]}
                        </Link>
                    ))}
                </nav>

                <label className={`${panel} block text-sm font-medium`}>
                    Company
                    <select className={input} value={props.company?.id ?? ''} onChange={(event) => router.get(paths[props.kind], event.target.value ? { company_id: event.target.value } : {})}>
                        <option value="">Select company</option>
                        {props.companies.map((company) => (
                            <option key={company.id} value={company.id}>
                                {company.code} — {company.name}{company.status !== 'active' ? ' (inactive)' : ''}
                            </option>
                        ))}
                    </select>
                </label>

                <Filters key={`${props.kind}:${props.company?.id ?? 0}:${JSON.stringify(props.filters)}`} {...props} />

                {report ? (
                    <>
                        <h2 className="text-lg font-semibold">
                            {report.binding.name} · {report.binding.role} · {report.binding.currency_code}
                            <span className="mt-1 block text-sm font-normal text-slate-500">{report.from} to {report.to} · GL #{report.binding.account_id} · {report.binding.status}</span>
                        </h2>
                        <Coverage key={`${props.kind}:${report.binding.id}:${report.from}:${report.to}`} report={report} />
                        <Summary report={report} />

                        <div className="flex flex-wrap gap-4 text-sm">
                            <a className="underline" href="#coverage-review">Review coverage and exceptions</a>
                            {props.canExport && props.company && (
                                <>
                                    <a className="underline" href={bookUrl(props.kind, props.company.id, props.filters, undefined, 'csv')}>Download CSV · all filtered rows</a>
                                    <a className="underline" href={bookUrl(props.kind, props.company.id, props.filters, undefined, 'print')} target="_blank" rel="noopener noreferrer">Print · all filtered rows</a>
                                </>
                            )}
                        </div>

                        <section className={`${panel} overflow-x-auto`}>
                            <table className="w-full text-left text-sm">
                                <caption className="sr-only">{titles[props.kind]} dated GL lines and source verification</caption>
                                <thead>
                                    <tr>{['Date', 'Document', 'Event / source', 'Debit', 'Credit', 'Posted GL balance', 'Source-verified balance', 'Verification', 'GL'].map((title) => <th key={title} scope="col" className="whitespace-nowrap p-3">{title}</th>)}</tr>
                                </thead>
                                <tbody>
                                    {report.rows.map((row) => (
                                        <tr className="border-t align-top dark:border-slate-700" key={row.journal_line_id}>
                                            <td className="whitespace-nowrap p-3">{row.date}</td>
                                            <td className="p-3">
                                                {row.source_url ? <Link className="underline" href={row.source_url}>{row.number ?? `Journal #${row.journal_id}`}</Link> : row.number ?? `Journal #${row.journal_id}`}
                                                <span className="block text-xs text-slate-500">GL line #{row.journal_line_id}</span>
                                            </td>
                                            <td className="p-3">
                                                {row.event?.replaceAll('_', ' ') ?? 'Source requires review'}
                                                {row.source_kind && <span className="block text-xs text-slate-500">{row.source_kind} #{row.source_id}</span>}
                                            </td>
                                            {[row.debit, row.credit, row.posted_balance, row.verified_balance].map((value, index) => <td className="whitespace-nowrap p-3 font-mono" key={index}>{money(value)}</td>)}
                                            <td className="p-3">{row.verified ? 'Source verified' : 'Review required'}</td>
                                            <td className="p-3">{row.journal_url ? <Link className="underline" href={row.journal_url}>Journal</Link> : '—'}</td>
                                        </tr>
                                    ))}
                                    {report.rows.length === 0 && <tr><td className="p-3" colSpan={9}>No posted movements on this page. Opening and full-period totals still apply; review coverage before interpreting an empty result.</td></tr>}
                                </tbody>
                            </table>
                        </section>

                        {props.company && (
                            <nav aria-label="Book pages" className="flex flex-wrap justify-between gap-4 text-sm">
                                <span>{report.pagination.total} rows · Page {report.pagination.current_page} of {report.pagination.last_page}. Totals cover all filtered rows.</span>
                                <div className="flex gap-4">
                                    {report.pagination.current_page > 1 && <Link href={bookUrl(props.kind, props.company.id, props.filters, report.pagination.current_page - 1)}>Previous</Link>}
                                    {report.pagination.current_page < report.pagination.last_page && <Link href={bookUrl(props.kind, props.company.id, props.filters, report.pagination.current_page + 1)}>Next</Link>}
                                </div>
                            </nav>
                        )}
                    </>
                ) : (
                    <p className={panel}>
                        {props.company
                            ? props.bindings.length > 0 ? 'Select a finance account and apply filters to view the book.' : `No ${props.kind} finance accounts are available in this company. Review setup with an authorized user.`
                            : 'Select a company to view its finance accounts.'}
                    </p>
                )}
            </div>
        </ERPLayout>
    );
}
