import { router, usePage } from '@inertiajs/react';
import type { Company } from './types';

type Props = { companies: Company[]; selectedId: number | null; path: string };

export default function CompanyFilter({ companies, selectedId, path }: Props) {
    const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
    const error = errors.company_id;

    return <div className="space-y-2">
        <label className="block text-sm font-medium" htmlFor="accounting-company">Company</label>
        <select
            id="accounting-company"
            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 sm:max-w-md"
            value={selectedId === null ? '' : String(selectedId)}
            aria-invalid={Boolean(error)}
            aria-describedby={error ? 'accounting-company-error' : undefined}
            onChange={(event) => router.get(path, event.target.value ? { company_id: event.target.value } : {}, { preserveScroll: true })}
        >
            <option value="">Choose a company</option>
            {companies.map(company => <option value={company.id} key={company.id}>{company.name} ({company.code}){company.status !== 'active' ? ' — inactive' : ''}</option>)}
        </select>
        {error && <p id="accounting-company-error" role="alert" className="text-sm text-red-700">{error}</p>}
        {companies.length === 0 && <p className="text-sm text-slate-600">No companies are available in your accounting scope.</p>}
    </div>;
}
