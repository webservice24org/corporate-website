import { router } from '@inertiajs/react';
import { base, input, panel } from './types';
import type { Context } from './types';

export function Notice({ issues }: { issues: string[] }) {
    return issues.length > 0 ? <div role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950"><ul>{issues.map(issue => <li key={issue}>{issue}</li>)}</ul></div> : null;
}
export function Errors({ errors }: { errors: Record<string, string> }) {
    const messages = Object.entries(errors);
    return messages.length > 0 ? <div role="alert" className="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900"><p className="font-medium">Please review these errors:</p><ul>{messages.map(([field, message]) => <li key={field}>{field}: {message}</li>)}</ul></div> : null;
}
export function CompanySelect({ companies, company }: Pick<Context, 'companies' | 'company'>) {
    return <label className={`${panel} block text-sm font-medium`}>Company<select className={`${input} mt-2`} value={company?.id ?? ''} onChange={event => router.get(base, event.target.value ? { company_id: event.target.value } : {})}><option value="">Select company</option>{companies.map(row => <option key={row.id} value={row.id}>{row.code} — {row.name}</option>)}</select></label>;
}
