import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { bookUrl, input, issueLabel, money, panel, paths } from './types';
import type { Props, Report } from './types';

export function Filters(props: Props) {
    const form = useForm({ ...props.filters, per_page: String(props.filters.per_page) });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!props.company) return;
        router.get(bookUrl(props.kind, props.company.id, {
            ...form.data, per_page: Number(form.data.per_page),
        }), {}, { preserveScroll: true });
    }

    return (
        <form className={`${panel} space-y-4`} onSubmit={submit}>
            <div className="grid gap-4 text-sm md:grid-cols-4">
                <label>Finance account
                    <select className={input} required value={form.data.binding_id} onChange={(event) => form.setData('binding_id', event.target.value)}>
                        <option value="">Select {props.kind === 'cash' ? 'cash' : 'bank'} account</option>
                        {props.bindings.map((binding) => (
                            <option key={binding.id} value={binding.id}>
                                {binding.name} · {binding.role} · {binding.currency_code}
                                {binding.status !== 'active' ? ' (inactive)' : ''}
                            </option>
                        ))}
                    </select>
                </label>
                <label>From
                    <input className={input} type="date" min="1900-01-01" max={props.today ?? undefined} required value={form.data.from} onChange={(event) => form.setData('from', event.target.value)} />
                </label>
                <label>To
                    <input className={input} type="date" min={form.data.from} max={props.today ?? undefined} required value={form.data.to} onChange={(event) => form.setData('to', event.target.value)} />
                </label>
                <label>Rows per page
                    <select className={input} value={form.data.per_page} onChange={(event) => form.setData('per_page', event.target.value)}>
                        {[10, 25, 50, 100].map((size) => <option value={size} key={size}>{size}</option>)}
                    </select>
                </label>
            </div>
            <div className="flex gap-4 text-sm">
                <button type="submit" disabled={!props.company} className="rounded-lg bg-slate-900 px-4 py-2 text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900">Apply filters</button>
                <Link className="self-center underline" href={props.company ? `${paths[props.kind]}?company_id=${props.company.id}` : paths[props.kind]}>Reset</Link>
            </div>
            {Object.keys(props.errors ?? {}).length > 0 && (
                <ul role="alert" className="text-sm text-red-700">
                    {Object.entries(props.errors ?? {}).map(([field, message]) => <li key={field}>{field}: {message}</li>)}
                </ul>
            )}
        </form>
    );
}

export function Coverage({ report }: { report: Report }) {
    const [page, setPage] = useState(1);
    const last = Math.max(1, Math.ceil(report.exceptions.length / 25));
    const current = Math.min(page, last);
    const review = !report.complete || !report.posted_totals_complete;

    return (
        <section id="coverage-review" role="status" className={`scroll-mt-4 rounded-xl border p-5 text-sm ${review ? 'border-amber-400 bg-amber-50 text-amber-950' : 'border-emerald-400 bg-emerald-50 text-emerald-950'}`}>
            <h2 className="font-semibold">{review ? 'Coverage review required — figures are provisional' : 'Verified within supported source scope'}</h2>
            <p className="mt-2">
                Posted GL and source-verified figures are separate. Source verification covers linked customer
                receipts, supplier payments, cash/bank transfers, approved bank charges/interest and their supported native reversals. Opening,
                manual, other-source and unbound legacy entries still require separate evidence review. These amounts do not establish bank statement
                agreement or available funds.
            </p>
            {!report.posted_totals_complete && (
                <p className="mt-3 font-semibold">Posted GL numeric projection is incomplete: {report.excluded_posted_lines} relevant posted line(s) were excluded.</p>
            )}
            <p className="mt-2">A zero closing difference alone does not prove complete coverage; gross debit/credit differences and exceptions still matter.</p>
            {report.exceptions.length === 0 ? (
                <p className="mt-2">No exceptions detected within the declared scope.</p>
            ) : (
                <>
                    <p className="mt-3 font-semibold">{report.exceptions.length} exception(s)</p>
                    <ul className="mt-2 space-y-2">
                        {report.exceptions.slice((current - 1) * 25, current * 25).map((issue, index) => (
                            <li key={`${current}:${index}`}>
                                {issueLabel(issue.code)}
                                {issue.journal_id !== null ? ` · Journal #${issue.journal_id}` : ''}
                                {issue.journal_line_id !== null ? ` · Line #${issue.journal_line_id}` : ''}
                                <span className="block text-xs">{issue.code}</span>
                            </li>
                        ))}
                    </ul>
                    {last > 1 && (
                        <nav className="mt-3 flex gap-3" aria-label="Coverage exception pages">
                            <button type="button" className="underline disabled:opacity-50" disabled={current <= 1} onClick={() => setPage(current - 1)}>Previous exceptions</button>
                            <span>{current} / {last}</span>
                            <button type="button" className="underline disabled:opacity-50" disabled={current >= last} onClick={() => setPage(current + 1)}>Next exceptions</button>
                        </nav>
                    )}
                </>
            )}
        </section>
    );
}

export function Summary({ report }: { report: Report }) {
    const groups = [
        { key: 'posted', title: report.posted_totals_complete ? 'Posted GL' : 'Posted GL · partial numeric projection', values: report.posted_gl },
        { key: 'verified', title: 'Source verified', values: report.source_verified },
        { key: 'difference', title: 'Difference · posted less verified', values: report.difference },
    ];

    return (
        <section className={`${panel} overflow-x-auto`}>
            <table className="w-full text-left text-sm">
                <caption className="mb-3 text-left font-semibold">Full filtered totals · {report.binding.currency_code}</caption>
                <thead><tr>{['Projection', 'Opening', 'Debits', 'Credits', 'Closing'].map((title) => <th key={title} scope="col" className="whitespace-nowrap p-3">{title}</th>)}</tr></thead>
                <tbody>
                    {groups.map((group) => (
                        <tr className="border-t" key={group.key}>
                            <th scope="row" className="p-3 font-medium">{group.title}</th>
                            {(['opening', 'debits', 'credits', 'closing'] as const).map((field) => <td className="whitespace-nowrap p-3 font-mono" key={field}>{money(group.values[field])}</td>)}
                        </tr>
                    ))}
                </tbody>
            </table>
            <p className="mt-3 text-sm text-slate-500">Opening + debits − credits = closing. Negative balances are retained. All totals cover the full selected date range.</p>
        </section>
    );
}
