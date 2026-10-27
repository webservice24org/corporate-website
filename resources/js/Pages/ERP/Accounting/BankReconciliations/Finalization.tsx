import Exports from './Exports';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import ERPLayout from '@/Layouts/erp-layout';
import { button, input, panel, secondary } from '../CashTransfers/types';
import { Errors, Issues, Table } from '../Banking/Shared';
import { requestKey } from '../Banking/helpers';

type Action = 'submit' | 'approve' | 'reject' | 'reopen';
type Props = {
    exportLinks?: Record<string, string>; errors?: Record<string, string>;
    reconciliation_id: number; company_id: number; revision: number; finalization_revision: number;
    state: string; technical_ready: boolean; stale: boolean; frozen: boolean;
    capabilities: Record<Action, boolean>; issues: string[]; requestKey: string; actorId: number;
    history: { id: number; sequence: number; kind: string; actor_id: number; reason: string; occurred_at: string }[];
    historyTruncated: boolean; workspaceUrl: string; readinessUrl: string;
};
const labels: Record<Action, string> = { submit: 'Submit current coverage', approve: 'Approve and finalize', reject: 'Return for review', reopen: 'Reopen reconciliation' };
export default function Finalization(props: Props) {
    return <ERPLayout><Head title={`Reconciliation #${props.reconciliation_id} finalization`} /><div className="space-y-6">
        <header className="flex flex-wrap justify-between gap-4"><div><h1 className="text-2xl font-semibold">Reconciliation #{props.reconciliation_id} · Finalization</h1><p className="mt-2 text-sm text-slate-500">Matching revision {props.revision} · Finalization revision {props.finalization_revision}</p></div><nav aria-label="Reconciliation review" className="flex flex-wrap gap-3"><Link className={secondary} href={props.workspaceUrl}>Matching workspace</Link><Link className={secondary} href={props.readinessUrl}>Coverage review</Link><Link className={secondary} href={`${props.workspaceUrl}/finalization`}>Refresh</Link></nav></header>
        <Exports links={props.exportLinks} error={props.errors?.export} /><Issues issues={props.issues} /><section role="status" className={`${panel} space-y-3`}><h2 className="text-xl font-semibold capitalize">{props.state}</h2><p className="text-sm">{props.state === 'finalized' ? 'Independently approved evidence freezes this bank cutoff. Earlier matching, statement admissions and bank posting cannot change it until authorized reopening.' : props.state === 'submitted' ? 'Submitted coverage awaits independent approval. Changes to matching or coverage require a fresh submission.' : props.state === 'review' ? 'Evidence could not be verified. Every action is blocked; this page does not reconstruct history.' : 'Review complete coverage before submission. Approval must be independent of the workspace maker, matching participants and submitter.'}</p><p className="text-sm text-slate-500">Technical coverage: {props.technical_ready ? 'checks pass' : 'review required'} · Cutoff changes: {props.frozen ? 'blocked by finalized evidence or integrity review' : 'not frozen'}. Finalization and reopening create no journal.</p></section>
        {(['submit', 'approve', 'reject', 'reopen'] as Action[]).filter(action => props.capabilities[action]).map(action => <ActionForm key={`${props.reconciliation_id}:${props.revision}:${props.finalization_revision}:${action}`} props={props} action={action} />)}
        {!Object.values(props.capabilities).some(Boolean) && <p className={panel}>No action is currently available for your permissions, evidence and revision. Review coverage and any later finalized bank dependencies.</p>}
        <Table caption="Preserved finalization history" headers={['Sequence / action', 'Actor', 'Time', 'Reason']}>{props.history.map(event => <tr key={event.id}><td className="p-3">{event.sequence} · {event.kind}</td><td className="p-3">#{event.actor_id}</td><td className="p-3 whitespace-nowrap">{event.occurred_at}</td><td className="p-3 whitespace-pre-wrap">{event.reason}</td></tr>)}{!props.history.length && <tr><td colSpan={4} className="p-5 text-center text-slate-500">No verified finalization events to display.</td></tr>}</Table>{props.historyTruncated && <p className="text-sm text-slate-500">Showing the latest 100 preserved events.</p>}
    </div></ERPLayout>;
}
function ActionForm({ props, action }: { props: Props; action: Action }) {
    const [key] = useState(() => requestKey(`${props.actorId}:${props.company_id}:finalization:${props.reconciliation_id}:${props.revision}:${props.finalization_revision}`, props.requestKey, action));
    const [confirmed, setConfirmed] = useState(false);
    const form = useForm({ revision: props.revision, finalization_revision: props.finalization_revision, reason: '', key });
    const confirm = action === 'approve' || action === 'reopen';
    function submit(event: FormEvent) { event.preventDefault(); if (confirm && !confirmed) return; form.post(`${props.workspaceUrl}/finalization/${action}`, { preserveScroll: true }); }
    return <form className={`${panel} space-y-4`} onSubmit={submit}><h2 className="text-lg font-semibold">{labels[action]}</h2><Errors errors={form.errors} />
        <p className="text-sm text-slate-500">{action === 'submit' ? 'The server captures complete current evidence. A new submission supersedes a stale pending submission and keeps its history.' : action === 'approve' ? 'Approval verifies the unchanged submitted evidence and earlier finalized dependencies, then freezes this cutoff atomically.' : action === 'reject' ? 'Return the request for review. Existing financial and matching history remains unchanged.' : 'Reopening preserves the approval and its evidence. Later finalized bank cutoffs must be reopened first. Matching and posting still require their native permissions.'}</p>
        <label className="block text-sm">Reason<textarea className={input} required maxLength={2000} rows={3} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
        {confirm && <label className="flex gap-3 text-sm"><input type="checkbox" required checked={confirmed} onChange={event => setConfirmed(event.target.checked)} />{action === 'approve' ? 'I reviewed the complete coverage and outstanding items and confirm independent approval.' : 'I reviewed the dependency order and understand that the previous approval remains in the audit history.'}</label>}
        <button className={button} disabled={form.processing || (confirm && !confirmed)}>{labels[action]}</button>
    </form>;
}
