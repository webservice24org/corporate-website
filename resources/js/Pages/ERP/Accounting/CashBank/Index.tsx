import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import ERPLayout from '@/Layouts/erp-layout';
import { base, input, label, pageUrl, panel } from './types';
import type { Binding, Props } from './types';

export default function Index(props: Props) {
    const report = props.report;

    return (
        <ERPLayout>
            <Head title="Cash and Bank Readiness" />
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Cash and Bank Readiness</h1>
                        <p className="mt-2 text-sm text-slate-500">
                            Review finance-account bindings, current posting profiles and source usage.
                        </p>
                    </div>
                    {props.canOpenSetup && props.company && (
                        <Link
                            className="rounded-lg border border-slate-300 px-4 py-2 text-sm underline"
                            href={`/erp/accounting/mappings/cash-accounts?company_id=${props.company.id}`}
                        >
                            Open finance account setup
                        </Link>
                    )}
                </header>

                <label className={`${panel} block text-sm font-medium`}>
                    Company
                    <select
                        className={input}
                        value={props.company?.id ?? ''}
                        onChange={(event) => router.get(
                            base,
                            event.target.value ? { company_id: event.target.value } : {},
                        )}
                    >
                        <option value="">Select company</option>
                        {props.companies.map((company) => (
                            <option key={company.id} value={company.id}>
                                {company.code} — {company.name} · {company.currency_code}
                                {company.status !== 'active' ? ' (inactive)' : ''}
                            </option>
                        ))}
                    </select>
                </label>

                {report ? (
                    <>
                        <section
                            role="status"
                            className={`rounded-xl border p-5 text-sm ${
                                report.status === 'ready_for_source_checks'
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-950'
                                    : 'border-amber-300 bg-amber-50 text-amber-950'
                            }`}
                        >
                            <h2 className="font-semibold">
                                {report.status === 'ready_for_source_checks'
                                    ? 'Binding setup is ready for source checks'
                                    : 'Review the setup items below'}
                            </h2>
                            <p className="mt-2">
                                Business date: {report.as_of ?? 'Needs timezone review'} · Currency: {report.currency}
                                {' · '}Accounting state: {report.accounting_state.replaceAll('_', ' ')}
                            </p>
                            <p className="mt-2">
                                {report.profile
                                    ? `Posting profile v${report.profile.version}, effective ${report.profile.effective_from}. Setup verification: ${report.profile.verified ? 'passed' : 'review required'}.`
                                    : 'No effective posting profile is available.'}
                            </p>
                            {report.issues.length > 0 && (
                                <ul className="mt-3 list-disc space-y-1 pl-5">
                                    {report.issues.map((issue) => <li key={issue}>{label(issue)}</li>)}
                                </ul>
                            )}
                        </section>

                        <section aria-label="Company readiness summary">
                            <h2 className="mb-3 text-sm font-semibold">Company summary · all finance accounts</h2>
                            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {[
                                    ['Finance accounts', report.summary.total_bindings],
                                    ['Cash accounts', report.summary.cash_bindings],
                                    ['Bank accounts', report.summary.bank_bindings],
                                    ['Active bindings passing setup', report.summary.usable_bindings],
                                    ['Inactive accounts', report.summary.inactive_bindings],
                                    ['Bindings requiring review', report.summary.review_bindings],
                                ].map(([title, value]) => (
                                    <div className={panel} key={title}>
                                        <dt className="text-sm text-slate-500">{title}</dt>
                                        <dd className="mt-2 text-2xl font-semibold">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </section>

                        {report.missing_bindings.length > 0 && (
                            <section className={panel}>
                                <h2 className="font-semibold">Current roles awaiting initialization</h2>
                                <ul className="mt-2 space-y-1 text-sm">
                                    {report.missing_bindings.map((row) => (
                                        <li key={row.role}>{row.role} · GL #{row.account_id}</li>
                                    ))}
                                </ul>
                                <p className="mt-2 text-sm text-slate-500">
                                    An authorized posting-profile publisher can initialize these in finance account setup.
                                </p>
                            </section>
                        )}

                        <Filters key={`${props.company?.id}:${JSON.stringify(props.filters)}`} {...props} />

                        <section className={`${panel} overflow-x-auto`}>
                            <table className="w-full text-left text-sm">
                                <caption className="sr-only">Finance account binding audit and source usage counts</caption>
                                <thead>
                                    <tr>
                                        {['Finance account', 'GL account', 'Availability', 'Binding structure', 'Usage counts'].map((title) => (
                                            <th className="whitespace-nowrap p-3" scope="col" key={title}>{title}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.rows.map((account) => <AccountRow key={account.id} account={account} />)}
                                    {report.rows.length === 0 && (
                                        <tr><td className="p-3" colSpan={5}>No finance accounts match this company, filter and page.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </section>

                        {props.company && (
                            <nav aria-label="Finance account pages" className="flex flex-wrap justify-between gap-4 text-sm">
                                <span>{report.pagination.total} matching accounts · Page {report.pagination.current_page} of {report.pagination.last_page}</span>
                                <div className="flex gap-4">
                                    {report.pagination.current_page > 1 && (
                                        <Link href={pageUrl(props.company.id, props.filters, report.pagination.current_page - 1)}>Previous</Link>
                                    )}
                                    {report.pagination.current_page < report.pagination.last_page && (
                                        <Link href={pageUrl(props.company.id, props.filters, report.pagination.current_page + 1)}>Next</Link>
                                    )}
                                </div>
                            </nav>
                        )}

                        <p className="text-sm text-slate-500">
                            Setup readiness is advisory. Each receipt, payment or journal still checks its own permissions,
                            accounting date, period and financial evidence when posting. Usage counts include document
                            history and are not financial balances or proof of recognition. Banking identifiers are not collected here.
                        </p>
                    </>
                ) : (
                    <p className={panel}>Select a company to review its cash and bank setup.</p>
                )}
            </div>
        </ERPLayout>
    );
}

function AccountRow({ account }: { account: Binding }) {
    return (
        <tr className="border-t align-top dark:border-slate-700">
            <th scope="row" className="p-3 font-medium">
                {account.name}
                <span className="mt-1 block text-xs font-normal text-slate-500">
                    {account.role} · {account.currency_code} · {account.kind}
                </span>
            </th>
            <td className="p-3">
                {account.gl ? `${account.gl.code} — ${account.gl.name}` : `GL #${account.account_id} requires review`}
            </td>
            <td className="p-3 capitalize">{account.status}</td>
            <td className="p-3">
                <span className="font-medium">
                    {account.setup_status === 'ready_for_source_checks' ? 'Passed setup checks' : account.setup_status}
                </span>
                {account.issues.length > 0 && (
                    <ul className="mt-2 space-y-1 text-xs">
                        {account.issues.map((issue) => <li key={issue}>{label(issue)}</li>)}
                    </ul>
                )}
            </td>
            <td className="whitespace-nowrap p-3">
                <p>Receipt documents: {account.usage.receipt_documents}</p>
                <p>Supplier payment documents: {account.usage.supplier_payment_documents}</p>
                <p>Posted journal lines: {account.usage.posted_journal_lines}</p>
            </td>
        </tr>
    );
}

function Filters(props: Props) {
    const form = useForm({ ...props.filters, per_page: String(props.filters.per_page) });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!props.company) return;
        router.get(pageUrl(props.company.id, { ...form.data, per_page: Number(form.data.per_page) }), {}, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className={`${panel} space-y-4`}>
            <div className="grid gap-4 text-sm md:grid-cols-4">
                <label>Search label, role or GL code
                    <input className={input} maxLength={100} value={form.data.q} onChange={(event) => form.setData('q', event.target.value)} />
                </label>
                <label>Kind
                    <select className={input} value={form.data.kind} onChange={(event) => form.setData('kind', event.target.value)}>
                        <option value="">Cash and bank</option><option value="cash">Cash</option><option value="bank">Bank</option>
                    </select>
                </label>
                <label>Availability
                    <select className={input} value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                        <option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                </label>
                <label>Rows per page
                    <select className={input} value={form.data.per_page} onChange={(event) => form.setData('per_page', event.target.value)}>
                        {[10, 25, 50, 100].map((size) => <option key={size} value={size}>{size}</option>)}
                    </select>
                </label>
            </div>
            <div className="flex gap-4 text-sm">
                <button type="submit" className="rounded-lg bg-slate-900 px-4 py-2 text-white dark:bg-slate-100 dark:text-slate-900">Apply filters</button>
                <Link className="self-center underline" href={props.company ? `${base}?company_id=${props.company.id}` : base}>Reset</Link>
            </div>
            {Object.entries(props.errors ?? {}).length > 0 && (
                <ul role="alert" className="text-sm text-red-700">
                    {Object.entries(props.errors ?? {}).map(([field, message]) => <li key={field}>{field}: {message}</li>)}
                </ul>
            )}
        </form>
    );
}
