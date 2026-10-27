import { Head, Link, useForm } from '@inertiajs/react';
import { useAuthorization } from '@/hooks/useAuthorization';
import ERPLayout from '@/Layouts/erp-layout';
import CompanyFilter from '../CompanyFilter';
import Readiness from '../Readiness';
import { button, companyUrl, panel } from '../types';
import type { WorkspaceProps } from '../types';

function InitializeSetup({ companyId }: { companyId: number }) {
    const form = useForm({ company_id: companyId });
    return <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); form.post('/erp/accounting/settings/initialize', { preserveScroll: true }); }}>
        <button type="submit" className={button} disabled={form.processing}>{form.processing ? 'Initializing…' : 'Initialize company setup'}</button>
        {Object.entries(form.errors).map(([key, message]) => <p className="text-sm text-red-700" role="alert" key={key}>{message}</p>)}
    </form>;
}

export default function Index(props: WorkspaceProps) {
    const { company, configuration } = props;
    const { can } = useAuthorization();
    return <ERPLayout><Head title="Accounting Setup" /><div className="space-y-6">
        <header className="flex flex-wrap items-start justify-between gap-3"><div><h1 className="text-2xl font-semibold">Accounting Setup</h1><p className="mt-1 text-sm text-slate-600">Initialize a company configuration with financial posting disabled.</p></div><Link className={button} href={companyUrl('/erp/accounting', props.filters.company_id)}>Accounting Overview</Link></header>
        <CompanyFilter companies={props.companies} selectedId={props.filters.company_id} path="/erp/accounting/settings" />
        <Readiness company={company} configuration={configuration} issues={props.issues} />
        {company && <section className={panel} aria-labelledby="company-accounting-config"><h2 id="company-accounting-config" className="text-lg font-semibold">Company configuration</h2>
            {configuration ? <><dl className="mt-4 grid gap-4 sm:grid-cols-2"><div><dt className="text-sm text-slate-600">Saved state</dt><dd className="mt-1 font-medium">{configuration.state}</dd></div><div><dt className="text-sm text-slate-600">Currency precision</dt><dd className="mt-1 font-medium">{configuration.currency_precision} decimal places</dd></div><div><dt className="text-sm text-slate-600">Recognition policy</dt><dd className="mt-1 font-medium">{configuration.recognition_policy === 'invoice_cogs' ? 'Invoice-time revenue and cost recognition' : 'Requires review'}</dd></div><div><dt className="text-sm text-slate-600">Revision</dt><dd className="mt-1 font-medium">{configuration.revision}</dd></div></dl><p className="mt-4 text-sm text-slate-600">Setup is saved. Review the starter chart and posting profile before continuing to fiscal periods and journals.</p></> : <div className="mt-3 space-y-4"><p className="text-sm text-slate-600">Initialization saves this company’s supported functional currency. It creates no account balances, journals or operational postings.</p>{props.canInitialize ? <InitializeSetup key={company.id} companyId={company.id} /> : <p className="text-sm text-slate-600">Initialization requires accounting setup permission and an active company with a supported currency.</p>}</div>}
        {can('erp.accounts.view') && <Link className={`${button} mt-4`} href={companyUrl('/erp/accounting/setup-readiness', company.id)}>Account Setup Readiness</Link>}</section>}
    </div></ERPLayout>;
}
