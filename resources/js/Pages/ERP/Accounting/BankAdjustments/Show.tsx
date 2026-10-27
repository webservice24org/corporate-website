import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { button, input, money, panel, secondary } from '../CashTransfers/types';
import { Errors, Frame, Issues, Table } from '../Banking/Shared';
import { requestKey } from '../Banking/helpers';
import type { AdjustmentProps } from '../Banking/types';
const path = '/erp/accounting/bank-adjustments';
export default function Show(props: AdjustmentProps) {
    const row = props.record;
    return <Frame context={props} heading={`${row.reverses_id ? 'Refund' : 'Adjustment'} #${row.id}`}><div className="flex flex-wrap gap-4 text-sm"><Link className="underline" href={path}>Register</Link>{props.statementUrl && <Link className="underline" href={props.statementUrl}>Source statement</Link>}{props.journalUrl && <Link className="underline" href={props.journalUrl}>Posted journal</Link>}{row.reverses_id && <Link className="underline" href={`${path}/${row.reverses_id}`}>Original adjustment #{row.reverses_id}</Link>}</div>
        <Issues issues={props.issues} /><section className={`${panel} grid gap-5 md:grid-cols-4`}><div><p className="text-sm text-slate-500">Status · revision {row.revision}</p><p className="mt-1 text-lg font-semibold capitalize">{row.status}</p></div><div><p className="text-sm text-slate-500">Amount</p><p className="mt-1 font-mono text-xl">{money(row.amount ?? '0.00')} {row.currency_code}</p></div><div><p className="text-sm text-slate-500">Bank · counter</p><p className="mt-1">#{row.cash_account_id} · #{row.counter_account_id}</p></div><div><p className="text-sm text-slate-500">Posting date · kind</p><p className="mt-1">{row.document_date} · {row.kind}</p></div><div className="md:col-span-4"><p className="text-sm text-slate-500">Reason</p><p className="mt-1 whitespace-pre-wrap">{row.reason}</p>{row.cancellation_reason && <p className="mt-2">Cancellation: {row.cancellation_reason}</p>}</div><p className="text-sm md:col-span-4">Maker #{row.created_by} · Submitter {row.submitted_by ? `#${row.submitted_by}` : 'pending'} · Approver {row.approved_by ? `#${row.approved_by}` : 'pending'} · Evidence: {props.financeState}</p></section>
        {!!props.preview.length && <Table caption={row.status === 'posted' ? 'Verified posting' : 'Proposed posting — no money recorded yet'} headers={['GL account', 'Bank binding', 'Debit', 'Credit']}>{props.preview.map((line, index) => <tr key={index}><td className="p-3">#{line.account_id}</td><td className="p-3">{line.cash_account_id ? `#${line.cash_account_id}` : '—'}</td><td className="p-3 font-mono">{money(line.debit)}</td><td className="p-3 font-mono">{money(line.credit)}</td></tr>)}</Table>}
        <section className="space-y-4"><div className="flex gap-3">{props.capabilities.edit && <Link className={secondary} href={`${path}/${row.id}/edit`}>Edit draft</Link>}{props.refundUrl && <Link className={secondary} href={props.refundUrl}>Prepare full refund</Link>}</div>
            {['submit', 'approve', 'reject', 'cancel', 'post'].filter(action => props.capabilities[action]).map(action => <ActionForm key={`${row.id}:${row.revision}:${action}`} props={props} action={action} />)}
        </section>
        {!!props.refunds.length && <section className={panel}><h2 className="font-semibold">Refund requests</h2><ul className="mt-3 space-y-2">{props.refunds.map(refund => <li key={refund.id}><Link className="underline" href={`${path}/${refund.id}`}>Refund #{refund.id} · {refund.status}</Link></li>)}</ul></section>}
        <Table caption="Workflow history" headers={['Action', 'Actor', 'Time', 'Reason']}>{props.history.map(event => <tr key={event.id}><td className="p-3">{event.action}</td><td className="p-3">#{event.actor_id}</td><td className="p-3 whitespace-nowrap">{event.occurred_at}</td><td className="p-3 whitespace-pre-wrap">{event.reason}</td></tr>)}</Table>{props.historyTruncated && <p className="text-sm">Showing the latest 100 workflow events.</p>}
    </Frame>;
}
function ActionForm({ props, action }: { props: AdjustmentProps; action: string }) {
    const row = props.record; const needsReason = action === 'reject' || action === 'cancel';
    const [key] = useState(() => requestKey(`${props.actorId}:${props.company?.id}:adjustment:${row.id}:${row.revision}`, props.requestKey, action));
    const form = useForm({ revision: row.revision, reason: '', key }); const [confirmed, setConfirmed] = useState(false);
    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform(data => action === 'post' ? { revision: data.revision, key: data.key } : needsReason ? { revision: data.revision, reason: data.reason } : { revision: data.revision });
        form.post(`${path}/${row.id}/${action}`, { preserveScroll: true });
    }
    return <form onSubmit={submit} className={`${panel} space-y-3`}><h2 className="font-semibold capitalize">{action === 'reject' ? 'Return to draft' : action}</h2><Errors errors={form.errors} />
        {action === 'approve' && <p className="text-sm">Approval confirms the submitted source, date, amount and counter account. The approver must be independent of the maker, editor and submitter.</p>}
        {action === 'post' && <label className="flex gap-3 text-sm"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} required />I reviewed the posting preview for {row.amount} {row.currency_code}. Posting records the bank movement and counter entry atomically; statement matching remains separate.</label>}
        {needsReason && <label className="block text-sm">Reason<textarea required maxLength={2000} rows={2} className={input} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>}
        <button className={button} disabled={form.processing || (action === 'post' && !confirmed)}>{action === 'reject' ? 'Return to draft' : action === 'post' ? 'Post approved adjustment' : action.charAt(0).toUpperCase() + action.slice(1)}</button>
    </form>;
}
