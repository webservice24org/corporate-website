import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { panel, input, secondary } from '../CashTransfers/types';
import { base, title } from './types';
import type { Context, Page } from './types';
export function Frame({ context, children, heading }: { context: Context; children: ReactNode; heading?: string }) {
    return <ERPLayout><Head title={heading ?? title(context.module)} /><div className="space-y-6">
        <header className="flex flex-wrap items-start justify-between gap-4"><div><h1 className="text-2xl font-semibold">{heading ?? title(context.module)}</h1><p className="mt-1 text-sm text-slate-500">Statement evidence, independent adjustment approval and explicit matching.</p></div><nav className="flex gap-3" aria-label="Bank workspaces">
            {context.canAdjust && <Link className={secondary} aria-current={context.module === 'adjustments' ? 'page' : undefined} href={url(base('adjustments'), { company_id: context.company?.id })}>Adjustments</Link>}
            {context.canReconcile && <Link className={secondary} aria-current={context.module === 'reconciliations' ? 'page' : undefined} href={url(base('reconciliations'), { company_id: context.company?.id })}>Reconciliation</Link>}
        </nav></header>{children}</div></ERPLayout>;
}
export function url(path: string, values: Record<string, string | number | boolean | null | undefined>) {
    const query = new URLSearchParams(); Object.entries(values).forEach(([key, value]) => { if (value !== undefined && value !== null && value !== '') query.set(key, value === true ? '1' : value === false ? '0' : String(value)); });
    const suffix = query.toString(); return `${path}${suffix ? `?${suffix}` : ''}`;
}
export function CompanySelect({ context }: { context: Context }) {
    return <label className={`${panel} block text-sm font-medium`}>Company<select className={input} value={context.company?.id ?? ''} onChange={event => router.get(base(context.module), event.target.value ? { company_id: event.target.value } : {})}><option value="">Select company</option>{context.companies.map(company => <option key={company.id} value={company.id}>{company.code} — {company.name}{company.status !== 'active' ? ' (inactive)' : ''}</option>)}</select></label>;
}
export function Errors({ errors }: { errors?: Record<string, string> }) {
    return errors && Object.keys(errors).length > 0 ? <ul role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">{Object.entries(errors).map(([key, value]) => <li key={key}>{key}: {value}</li>)}</ul> : null;
}
export function Issues({ issues }: { issues: string[] }) {
    return issues.length ? <ul role="alert" className="rounded-lg border border-amber-400 bg-amber-50 p-4 text-sm text-amber-950">{issues.map(issue => <li key={issue}>{issue}</li>)}</ul> : null;
}
export function Pages({ page, path, filters = {} }: { page: Page; path: string; filters?: Record<string, string | number | boolean | null | undefined> }) {
    return <nav aria-label={`${page.parameter} pagination`} className="flex flex-wrap justify-between gap-3 text-sm"><span>{page.total} rows · Page {page.current} of {page.last}</span><div className="flex gap-4">{page.current > 1 && <Link preserveState preserveScroll href={url(path, { ...filters, [page.parameter]: page.current - 1 })}>Previous</Link>}{page.current < page.last && <Link preserveState preserveScroll href={url(path, { ...filters, [page.parameter]: page.current + 1 })}>Next</Link>}</div></nav>;
}
export function Table({ headers, children, caption }: { headers: string[]; children: ReactNode; caption: string }) {
    return <div className={`${panel} overflow-x-auto`}><table className="w-full text-left text-sm"><caption className="mb-3 text-left text-lg font-semibold">{caption}</caption><thead><tr>{headers.map(header => <th scope="col" className="whitespace-nowrap p-3" key={header}>{header}</th>)}</tr></thead><tbody className="divide-y divide-slate-200 dark:divide-slate-700">{children}</tbody></table></div>;
}
