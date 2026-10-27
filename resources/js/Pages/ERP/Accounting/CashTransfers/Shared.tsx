import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { base, input, panel, secondary, transferUrl } from './types';
import type { Binding, Context, Status } from './types';

export function Frame({ title, context, children }: { title: string; context: Context; children: ReactNode }) {
    return (
        <ERPLayout>
            <Head title={title} />
            <div className="space-y-5">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-sm font-medium text-slate-500">Accounting · {context.company?.name ?? 'Select company'}</p>
                        <h1 className="mt-1 text-2xl font-semibold">{title}</h1>
                        <p className="mt-1 max-w-3xl text-sm text-slate-500">Move funds between cash and bank accounts in the same company and currency.</p>
                    </div>
                    <Link href={transferUrl(context.company?.id)} className={secondary}>Transfer register</Link>
                </header>
                {context.issues.length > 0 && (
                    <section role="status" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                        <ul className="list-inside list-disc space-y-1">{context.issues.map(issue => <li key={issue}>{issue}</li>)}</ul>
                    </section>
                )}
                {children}
            </div>
        </ERPLayout>
    );
}

export function Errors({ errors }: { errors: Record<string, string | undefined> }) {
    const entries = Object.entries(errors).filter((entry): entry is [string, string] => Boolean(entry[1]));
    return entries.length > 0 ? (
        <div role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">
            <p className="font-semibold">Please review the following:</p>
            <ul className="mt-1 list-inside list-disc">{entries.map(([field, message]) => <li key={field}>{message}</li>)}</ul>
        </div>
    ) : null;
}

export function CompanySelect({ context }: { context: Context }) {
    return (
        <label className={`${panel} block text-sm font-medium`}>
            Company
            <select className={input} value={context.company?.id ?? ''} onChange={event => router.get(base, event.target.value ? { company_id: event.target.value } : {})}>
                <option value="">Select company</option>
                {context.companies.map(company => <option key={company.id} value={company.id}>{company.code} — {company.name}{company.status !== 'active' ? ' (inactive)' : ''}</option>)}
            </select>
        </label>
    );
}

const statusStyles: Record<Status, string> = {
    draft: 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200',
    submitted: 'bg-blue-100 text-blue-900', approved: 'bg-violet-100 text-violet-900',
    posted: 'bg-emerald-100 text-emerald-900', cancelled: 'bg-rose-100 text-rose-900',
};
export function StatusBadge({ status }: { status: Status }) {
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold capitalize ${statusStyles[status] ?? statusStyles.draft}`}>{status}</span>;
}

export function AccountLabel({ binding }: { binding: Binding | null }) {
    return binding ? (
        <span className="block">
            <span className="font-medium">{binding.name}</span>
            <span className="mt-0.5 block text-xs text-slate-500">{binding.kind} · {binding.role}{binding.status !== 'active' ? ' · inactive' : ''}</span>
        </span>
    ) : <span className="text-sm text-amber-700">Account unavailable</span>;
}
