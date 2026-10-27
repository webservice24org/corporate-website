import { router } from '@inertiajs/react';
import { base, input, panel } from './types';
import type { Context } from './types';
export function Errors({ errors }: { errors: Record<string, string> }) {
    return Object.keys(errors).length > 0 ? <div role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900"><p className="font-semibold">Please review:</p><ul>{Object.entries(errors).map(([field, message]) => <li key={field}>{field}: {message}</li>)}</ul></div> : null;
}
export function Notice({ issues }: { issues: string[] }) {
    return issues.length > 0 ? <div role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"><ul>{issues.map(issue => <li key={issue}>{issue}</li>)}</ul></div> : null;
}
export function CompanySelect({ companies, company }: Pick<Context, 'companies' | 'company'>) {
    return <label className={`${panel} block text-sm font-medium`}>Company<select className={input} value={company?.id ?? ''} onChange={event => router.get(base, event.target.value ? { company_id: event.target.value } : {})}><option value="">Select company</option>{companies.map(row => <option key={row.id} value={row.id}>{row.code} — {row.name}</option>)}</select></label>;
}
