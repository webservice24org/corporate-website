import { Link, router } from '@inertiajs/react';
import { button, input, money, panel } from '../CashTransfers/types';
import { base } from './types';
import type { RegisterProps } from './types';
import { CompanySelect, Errors, Frame, Pages, Table, url } from './Shared';
export default function Register(props: RegisterProps) {
    const path = base(props.module); const filters = { company_id: props.company?.id, ...props.filters };
    return <Frame context={props}><CompanySelect context={props} /><Errors errors={props.errors} />
        <div className="flex flex-wrap items-end justify-between gap-4"><label className="min-w-64 text-sm">Bank<select className={input} value={props.filters.cash_account_id} onChange={event => router.get(path, { ...filters, cash_account_id: event.target.value })}><option value="">All banks</option>{props.banks.map(bank => <option key={bank.id} value={bank.id}>{bank.name} · {bank.currency_code} · {bank.status}</option>)}</select></label>
            {props.module === 'adjustments' && <label className="text-sm">Status<select className={input} value={props.filters.status} onChange={event => router.get(path, { ...filters, status: event.target.value })}><option value="">All statuses</option>{['draft', 'submitted', 'approved', 'posted', 'cancelled'].map(status => <option key={status}>{status}</option>)}</select></label>}
            {props.canCreate && <Link className={button} href={url(`${path}/create`, filters)}>{props.module === 'adjustments' ? 'Prepare adjustment' : 'Open workspace'}</Link>}
        </div>
        {props.module === 'reconciliations' && <p className={panel}>Working reconciliation only. A zero difference does not verify opening coverage or finalize a statement.</p>}
        <Table caption="Register" headers={['Reference', 'Bank', 'Date / period', 'Status', 'Amount']}>
            {props.records.data.map(row => <tr key={row.id}><td className="p-3"><Link className="underline" href={`${path}/${row.id}`}>{row.statement_reference ?? `${row.reverses_id ? 'Refund' : row.kind} #${row.id}`}</Link><p className="text-xs text-slate-500">Revision {row.revision}</p></td><td className="p-3">{props.banks.find(bank => bank.id === row.cash_account_id)?.name ?? `Bank #${row.cash_account_id}`}</td><td className="p-3 whitespace-nowrap">{row.document_date ?? `${row.starts_on} – ${row.ends_on}`}</td><td className="p-3 capitalize">{row.status}</td><td className="p-3 whitespace-nowrap font-mono">{row.amount ? `${money(row.amount)} ${row.currency_code}` : row.currency_code}</td></tr>)}
            {!props.records.data.length && <tr><td colSpan={5} className="p-6 text-center text-slate-500">{props.company ? 'No records for these filters.' : 'Select a company to view its records.'}</td></tr>}
        </Table><Pages path={path} filters={filters} page={{ current: props.records.current_page, last: props.records.last_page, total: props.records.total, parameter: 'page' }} />
    </Frame>;
}
