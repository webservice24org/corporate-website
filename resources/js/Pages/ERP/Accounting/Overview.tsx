import { Head, Link } from '@inertiajs/react';
import { useAuthorization } from '@/hooks/useAuthorization';
import ERPLayout from '@/Layouts/erp-layout';
import CompanyFilter from './CompanyFilter';
import Readiness from './Readiness';
import { button, companyUrl, panel } from './types';
import type { WorkspaceProps } from './types';

type Stage = { title: string; description: string; href?: string; permission?: string };
const stages: Stage[] = [
    { title: 'Chart of Accounts', description: 'Organize company accounts and review their classifications.', href: '/erp/accounting/accounts', permission: 'erp.accounts.view' },
    { title: 'Account Setup Readiness', description: 'Preview the starter chart and check profile coverage.', href: '/erp/accounting/setup-readiness', permission: 'erp.accounts.view' },
    { title: 'Posting Profiles', description: 'Review immutable effective-dated account mappings.', href: '/erp/accounting/mappings', permission: 'erp.accounting-mappings.view' },
    { title: 'Fiscal Calendar', description: 'Configure nonoverlapping fiscal years and accounting periods.', href: '/erp/accounting/calendar', permission: 'erp.fiscal-years.view' },
    { title: 'Journal Entries', description: 'Review, approve and post balanced entries in a later milestone.' },
    { title: 'Operational Integration', description: 'Connect approved Purchase, Sales and settlement events to the ledger in a later milestone.' },
];

export default function Overview(props: WorkspaceProps) {
    const { can } = useAuthorization();
    return <ERPLayout><Head title="Accounting Overview" /><div className="space-y-6"><header className="flex flex-wrap items-start justify-between gap-3"><div><h1 className="text-2xl font-semibold">Accounting Overview</h1><p className="mt-1 text-sm text-slate-600">Company setup and the next steps toward financial posting.</p></div><Link className={button} href={companyUrl('/erp/accounting/settings', props.filters.company_id)}>Accounting Setup</Link></header><CompanyFilter companies={props.companies} selectedId={props.filters.company_id} path="/erp/accounting" /><Readiness company={props.company} configuration={props.configuration} issues={props.issues} /><section className={panel}><h2 className="text-lg font-semibold">Accounting workspace</h2><p className="mt-1 text-sm text-slate-600">Manage company configuration. Setup does not activate financial posting.</p><div className="mt-4 grid gap-4 md:grid-cols-2">{stages.map(stage => <article className="rounded-lg border border-slate-200 p-4" key={stage.title}><div className="flex flex-wrap justify-between gap-2"><h3 className="font-medium">{stage.title}</h3><span className="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">{stage.permission ? (can(stage.permission) ? 'Available' : 'Restricted') : 'Upcoming'}</span></div><p className="mt-2 text-sm text-slate-600">{stage.description}</p>{stage.href && stage.permission && can(stage.permission) && <Link className="mt-3 inline-block underline" href={companyUrl(stage.href, props.filters.company_id)}>Open {stage.title}</Link>}</article>)}</div></section></div></ERPLayout>;
}
