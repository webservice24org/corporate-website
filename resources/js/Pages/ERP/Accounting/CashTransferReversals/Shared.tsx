import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { base, input, panel, secondary, transferUrl } from './types';
import type { Context } from './types';

export function Frame({ title, context, children }: { title: string; context: Context; children: ReactNode }) {
    return (
        <ERPLayout>
            <Head title={title} />
            <div className="space-y-5">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-sm font-medium text-slate-500">Accounting · {context.company?.name ?? 'Select company'}</p>
                        <h1 className="mt-1 text-2xl font-semibold">{title}</h1>
                        <p className="mt-1 max-w-3xl text-sm text-slate-500">Reverse a posted transfer with independent approval. The original amount and accounts stay fixed.</p>
                    </div>
                    <Link href={transferUrl(context.company?.id)} className={secondary}>Reversal register</Link>
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

export { AccountLabel, StatusBadge } from '../CashTransfers/Shared';

export function OriginalCard({ original }: { original: import('./types').Original | null }) {
    if (!original) return <p role="status" className={panel}>Original evidence needs review.</p>;
    return (
        <section className={panel}>
            <h2 className="font-semibold">Original transfer · {original.number}</h2>
            <p className="mt-2 text-sm">{original.document_date} · {original.amount} {original.currency_code}</p>
            <p className="mt-2 text-sm">Original source: {original.source?.name ?? 'Unavailable'} · GL #{original.source_account_id}</p>
            <p className="mt-1 text-sm">Original destination: {original.destination?.name ?? 'Unavailable'} · GL #{original.destination_account_id}</p>
            <p className="mt-3 text-sm text-slate-500">The reversal returns funds to the original source and removes them from the original destination. Inactive finance bindings retain their original identity.</p>
            <div className="mt-3 flex flex-wrap gap-4 text-sm">
                {original.url && <Link className="underline" href={original.url}>Original transfer</Link>}
                {original.journal_url && <Link className="underline" href={original.journal_url}>Original journal</Link>}
            </div>
        </section>
    );
}
