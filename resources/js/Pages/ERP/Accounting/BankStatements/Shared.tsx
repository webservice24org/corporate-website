import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { base, input, money, panel, registerUrl, secondary } from './types';
import type { Context, Row } from './types';
export { Errors } from '../CashTransfers/Shared';

export function Frame({ title, context, children }: { title: string; context: Context; children: ReactNode }) {
    return (
        <ERPLayout>
            <Head title={title} />
            <div className="space-y-5">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div><p className="text-sm text-slate-500">Accounting · {context.company?.name ?? 'Select company'}</p><h1 className="mt-1 text-2xl font-semibold">{title}</h1><p className="mt-2 max-w-3xl text-sm text-slate-500">Bank statement evidence only. Recording a statement creates no journal and does not certify reconciliation.</p></div>
                    <Link href={registerUrl(context.company?.id)} className={secondary}>Statement register</Link>
                </header>
                {children}
            </div>
        </ERPLayout>
    );
}
export function CompanySelect({ context }: { context: Context }) {
    return <label className={`${panel} block text-sm`}>Company<select className={input} value={context.company?.id ?? ''} onChange={event => router.get(base, event.target.value ? { company_id: event.target.value } : {})}><option value="">Select company</option>{context.companies.map(company => <option key={company.id} value={company.id}>{company.code} — {company.name}{company.status !== 'active' ? ' (inactive)' : ''}</option>)}</select></label>;
}
export function Rows({ rows }: { rows: Row[] }) {
    return <div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Bank statement transactions in original order</caption><thead><tr>{['Row / Date', 'Transaction / Reference', 'Description', 'Money in', 'Money out', 'Calculated balance', 'Bank balance'].map(label => <th key={label} scope="col" className="whitespace-nowrap p-3">{label}</th>)}</tr></thead><tbody>{rows.map(row => <tr key={row.line_number} className="border-t border-slate-200 dark:border-slate-700"><td className="whitespace-nowrap p-3">#{row.line_number} · {row.date}{row.value_date && <span className="mt-1 block text-xs text-slate-500">Value: {row.value_date}</span>}</td><td className="max-w-xs break-words p-3">{row.transaction_id || 'No bank ID'}<span className="mt-1 block text-xs text-slate-500">{row.reference}</span></td><td className="min-w-48 whitespace-pre-wrap break-words p-3">{row.description}</td><td className="whitespace-nowrap p-3 font-mono">{money(row.money_in)}</td><td className="whitespace-nowrap p-3 font-mono">{money(row.money_out)}</td><td className="whitespace-nowrap p-3 font-mono">{money(row.running_balance)}</td><td className="whitespace-nowrap p-3 font-mono">{row.balance === null ? 'Not supplied' : money(row.balance)}</td></tr>)}{rows.length === 0 && <tr><td colSpan={7} className="p-5 text-center text-slate-500">No movement rows.</td></tr>}</tbody></table></div>;
}
