import type { WorkspaceProps } from './types';
import { panel } from './types';

type Props = Pick<WorkspaceProps, 'company' | 'configuration' | 'issues'>;

export default function Readiness({ company, configuration, issues }: Props) {
    if (!company) return <div className={panel}><p>Select a company to view its accounting setup.</p></div>;

    return <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div className={panel}><p className="text-sm text-slate-600">Company</p><p className="mt-2 break-words font-semibold">{company.name}</p><p className="mt-1 text-xs text-slate-500">{company.code} · {company.status}</p></div>
            <div className={panel}><p className="text-sm text-slate-600">Setup</p><p className="mt-2 font-semibold">{configuration ? 'Initialized' : 'Not initialized'}</p><p className="mt-1 text-xs text-slate-500">{configuration ? `Saved state: ${configuration.state}` : 'Company setup has not been saved.'}</p></div>
            <div className={panel}><p className="text-sm text-slate-600">Functional currency</p><p className="mt-2 font-semibold">{configuration?.functional_currency ?? company.currency_code}</p><p className="mt-1 break-words text-xs text-slate-500">{company.timezone}</p></div>
            <div className={panel}><p className="text-sm text-slate-600">Journal posting</p><p className="mt-2 font-semibold text-amber-800">Unavailable</p><p className="mt-1 text-xs text-slate-500">This workspace currently supports setup only.</p></div>
        </div>
        {issues.length > 0 && <div role="alert" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"><p className="font-semibold">Setup requires attention</p><ul className="mt-2 list-disc space-y-1 pl-5">{issues.map(issue => <li key={issue}>{issue}</li>)}</ul></div>}
    </div>;
}
