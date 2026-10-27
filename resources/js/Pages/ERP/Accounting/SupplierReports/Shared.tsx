import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

import ERPLayout from '@/Layouts/erp-layout';
import { button, input, panel, paths, reportUrl, titles } from './types';
import type { BaseReport, Bridge, Context, Kind, Page } from './types';

const reportKinds: Kind[] = ['statement', 'payables', 'reconciliation'];
const pageSizes = [10, 25, 50, 100];

type ContextProps = { context: Context };

export function Frame({ context, children }: ContextProps & { children: ReactNode }) {
    const visibleReports = reportKinds.filter(
        (kind) => kind !== 'reconciliation' || context.canReconcile,
    );
    const filterKey = `${context.kind}:${context.company?.id ?? 0}:${JSON.stringify(context.filters)}`;

    return (
        <ERPLayout>
            <div className="space-y-5">
                <header>
                    <h1 className="text-2xl font-semibold">{titles[context.kind]}</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Dated supplier positions in the company currency. AP and advances are shown separately.
                    </p>
                </header>

                <nav aria-label="Supplier reports" className="flex flex-wrap gap-3 text-sm">
                    {visibleReports.map((kind) => (
                        <Link
                            key={kind}
                            aria-current={context.kind === kind ? 'page' : undefined}
                            className={`rounded-lg border px-3 py-2 ${
                                context.kind === kind
                                    ? 'border-slate-900 font-semibold dark:border-white'
                                    : 'border-slate-300'
                            }`}
                            href={
                                context.company
                                    ? `${paths[kind]}?company_id=${context.company.id}`
                                    : paths[kind]
                            }
                        >
                            {titles[kind]}
                        </Link>
                    ))}
                </nav>

                <label className={`${panel} block text-sm font-medium`}>
                    Company
                    <select
                        className={input}
                        value={context.company?.id ?? ''}
                        onChange={(event) =>
                            router.get(
                                paths[context.kind],
                                event.target.value ? { company_id: event.target.value } : {},
                            )
                        }
                    >
                        <option value="">Select company</option>
                        {context.companies.map((company) => (
                            <option key={company.id} value={company.id}>
                                {company.code} — {company.name}
                                {company.status !== 'active' ? ' (inactive)' : ''}
                            </option>
                        ))}
                    </select>
                </label>

                <FiltersForm key={filterKey} context={context} />

                <p className="text-sm text-slate-600 dark:text-slate-300">
                    Coverage: verified live sources only. Legacy openings require separate coverage
                    verification. Unknown balances remain unknown. This screen does not certify the
                    future accounting activation checklist.
                </p>

                {children}
            </div>
        </ERPLayout>
    );
}

function FiltersForm({ context }: ContextProps) {
    const form = useForm({
        ...context.filters,
        per_page: String(context.filters.per_page),
    });
    const errors = context.errors ?? {};
    const resetUrl = context.company
        ? `${paths[context.kind]}?company_id=${context.company.id}`
        : paths[context.kind];

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!context.company) {
            return;
        }

        const filters = { ...form.data, per_page: Number(form.data.per_page) };
        router.get(reportUrl(context.kind, context.company.id, filters), {}, {
            preserveScroll: true,
        });
    }

    return (
        <form onSubmit={submit} className={`${panel} space-y-4`}>
            <div className="grid gap-4 text-sm md:grid-cols-4">
                {context.kind !== 'reconciliation' && (
                    <label>
                        Supplier
                        <select
                            className={input}
                            value={form.data.supplier_id}
                            required={context.kind === 'statement'}
                            onChange={(event) => form.setData('supplier_id', event.target.value)}
                        >
                            <option value="">
                                {context.kind === 'statement' ? 'Select supplier' : 'All suppliers'}
                            </option>
                            {context.suppliers.map((supplier) => (
                                <option value={supplier.id} key={supplier.id}>
                                    {supplier.code} — {supplier.name}
                                    {supplier.status !== 'active' ? ' (inactive)' : ''}
                                </option>
                            ))}
                        </select>
                    </label>
                )}

                {context.kind === 'statement' ? (
                    <>
                        <label>
                            From
                            <input
                                className={input}
                                type="date"
                                min="1900-01-01"
                                max={context.today ?? undefined}
                                required
                                value={form.data.from}
                                onChange={(event) => form.setData('from', event.target.value)}
                            />
                        </label>
                        <label>
                            To
                            <input
                                className={input}
                                type="date"
                                min={form.data.from}
                                max={context.today ?? undefined}
                                required
                                value={form.data.to}
                                onChange={(event) => form.setData('to', event.target.value)}
                            />
                        </label>
                    </>
                ) : (
                    <label>
                        As of
                        <input
                            className={input}
                            type="date"
                            min="1900-01-01"
                            max={context.today ?? undefined}
                            required
                            value={form.data.as_of}
                            onChange={(event) => form.setData('as_of', event.target.value)}
                        />
                    </label>
                )}

                {context.kind !== 'reconciliation' && (
                    <label>
                        Rows per page
                        <select
                            className={input}
                            value={form.data.per_page}
                            onChange={(event) => form.setData('per_page', event.target.value)}
                        >
                            {pageSizes.map((size) => (
                                <option key={size} value={size}>{size}</option>
                            ))}
                        </select>
                    </label>
                )}
            </div>

            <div className="flex flex-wrap gap-4">
                <button type="submit" className={button} disabled={!context.company}>
                    Apply filters
                </button>
                <Link className="self-center text-sm underline" href={resetUrl}>Reset</Link>
            </div>

            {Object.keys(errors).length > 0 && (
                <ul role="alert" className="text-sm text-red-700">
                    {Object.entries(errors).map(([field, message]) => (
                        <li key={field}>{field}: {message}</li>
                    ))}
                </ul>
            )}
        </form>
    );
}

export function ExportLinks({ context, ready }: ContextProps & { ready: boolean }) {
    if (!context.canExport || !context.company || !ready) {
        return null;
    }

    const csvUrl = reportUrl(context.kind, context.company.id, context.filters, undefined, 'csv');
    const printUrl = reportUrl(context.kind, context.company.id, context.filters, undefined, 'print');

    return (
        <div className="flex flex-wrap gap-4 text-sm">
            <a className={button} href={csvUrl}>Download CSV · all filtered rows</a>
            <a
                className="self-center underline"
                href={printUrl}
                target="_blank"
                rel="noopener noreferrer"
            >
                Print · all filtered rows
            </a>
        </div>
    );
}

export function Coverage({ report, bridge }: { report: BaseReport; bridge?: Bridge }) {
    const review = !report.complete || (bridge !== undefined && !bridge.reconciled);
    const statusClass = review
        ? 'border-amber-400 bg-amber-50 text-amber-950'
        : 'border-emerald-400 bg-emerald-50 text-emerald-950';
    const heading = review
        ? 'Review required — figures are provisional'
        : bridge
            ? 'Reconciled within declared live-source coverage'
            : 'Sources verified within declared live-source coverage';

    return (
        <section role="status" className={`rounded-xl border p-4 text-sm ${statusClass}`}>
            <h2 className="font-semibold">{heading}</h2>
            <p className="mt-1">
                {bridge?.scope === 'supplier_assigned_controls'
                    ? 'This GL check covers this supplier’s assigned control lines. Use company AP Reconciliation to review unassigned entries.'
                    : 'A zero difference alone does not prove complete evidence. Exceptions must also be resolved.'}
            </p>
            <Exceptions issues={report.exceptions} />
        </section>
    );
}

function Exceptions({ issues }: { issues: BaseReport['exceptions'] }) {
    const [page, setPage] = useState(1);
    const size = 25;
    const last = Math.max(1, Math.ceil(issues.length / size));
    const current = Math.min(page, last);
    const visibleIssues = issues.slice((current - 1) * size, current * size);

    if (issues.length === 0) {
        return <p className="mt-2">No exceptions detected within the declared scope.</p>;
    }

    return (
        <div className="mt-3">
            <p className="font-medium">{issues.length} exception(s)</p>
            <ul className="mt-2 space-y-1">
                {visibleIssues.map((issue, index) => (
                    <li key={`${current}:${index}`}>
                        {issue.code.replaceAll('_', ' ')}
                        {issue.supplier_id !== null ? ` · Supplier #${issue.supplier_id}` : ''}
                        {issue.source_kind ? ` · ${issue.source_kind} #${issue.source_id}` : ''}
                        {issue.journal_line_id ? ` · GL line #${issue.journal_line_id}` : ''}
                        {issue.account_id ? ` · Account #${issue.account_id}` : ''}
                    </li>
                ))}
            </ul>

            {last > 1 && (
                <nav aria-label="Exception pages" className="mt-3 flex gap-3">
                    <button
                        type="button"
                        className="underline disabled:opacity-50"
                        disabled={current <= 1}
                        onClick={() => setPage(current - 1)}
                    >
                        Previous exceptions
                    </button>
                    <span>{current} / {last}</span>
                    <button
                        type="button"
                        className="underline disabled:opacity-50"
                        disabled={current >= last}
                        onClick={() => setPage(current + 1)}
                    >
                        Next exceptions
                    </button>
                </nav>
            )}
        </div>
    );
}

export function Pagination({ context, page }: ContextProps & { page: Page }) {
    if (!context.company) {
        return null;
    }

    return (
        <nav aria-label="Report pages" className="flex flex-wrap justify-between gap-4 text-sm">
            <span>
                {page.total} rows · Page {page.current_page} of {page.last_page}.
                {' '}Totals cover all filtered rows.
            </span>
            <div className="flex gap-4">
                {page.current_page > 1 && (
                    <Link href={reportUrl(context.kind, context.company.id, context.filters, page.current_page - 1)}>
                        Previous
                    </Link>
                )}
                {page.current_page < page.last_page && (
                    <Link href={reportUrl(context.kind, context.company.id, context.filters, page.current_page + 1)}>
                        Next
                    </Link>
                )}
            </div>
        </nav>
    );
}

export function Empty({ context }: ContextProps) {
    return (
        <p className={panel}>
            {context.company
                ? 'Select a supplier and apply filters to view its statement.'
                : 'Select a company to view this report.'}
        </p>
    );
}

export function Table({ caption, headers, children }: {
    caption: string;
    headers: string[];
    children: ReactNode;
}) {
    return (
        <div className={`${panel} overflow-x-auto`}>
            <table className="w-full text-left text-sm">
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr>
                        {headers.map((header) => (
                            <th key={header} scope="col" className="whitespace-nowrap p-3">
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

export function Totals({ values, currency }: {
    values: { label: string; value: string }[];
    currency: string;
}) {
    return (
        <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {values.map((row) => (
                <div className={panel} key={row.label}>
                    <dt className="text-sm text-slate-500">{row.label} · {currency}</dt>
                    <dd className="mt-1 break-all font-mono text-xl font-semibold">{row.value}</dd>
                </div>
            ))}
        </dl>
    );
}
