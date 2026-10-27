import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { AccountLabel, Errors, Frame, OriginalCard, StatusBadge } from './Shared';
import { base, button, input, keyScope, money, panel, secondary, stableKey } from './types';
import type { DetailProps, Reversal } from './types';

function SimpleAction({ transfer, action, label }: { transfer: Reversal; action: 'submit' | 'approve'; label: string }) {
    const form = useForm({ revision: transfer.revision });
    function submit(event: FormEvent) { event.preventDefault(); form.post(`${base}/${transfer.id}/${action}`, { preserveScroll: true }); }
    return <form onSubmit={submit} className="space-y-3"><button className={button} disabled={form.processing}>{form.processing ? 'Working…' : label}</button><Errors errors={form.errors} /></form>;
}

function ReasonAction({ transfer, action }: { transfer: Reversal; action: 'reject' | 'cancel' }) {
    const form = useForm({ revision: transfer.revision, reason: '' });
    function submit(event: FormEvent) { event.preventDefault(); form.post(`${base}/${transfer.id}/${action}`, { preserveScroll: true }); }
    return (
        <form onSubmit={submit} className={`${panel} space-y-3`}>
            <h2 className="font-semibold">{action === 'reject' ? 'Return for revision' : 'Cancel reversal request'}</h2>
            <label className="block text-sm">Reason<textarea className={input} required rows={2} maxLength={2000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
            <Errors errors={form.errors} />
            <button className={secondary} disabled={form.processing}>{form.processing ? 'Working…' : action === 'reject' ? 'Return to draft' : 'Cancel reversal request'}</button>
        </form>
    );
}

function PostAction({ context }: { context: DetailProps }) {
    const row = context.reversal;
    const scope = keyScope(context.actorId, row.company_id, String(row.id), row.revision);
    const [key] = useState(() => stableKey(scope, context.requestKey));
    const form = useForm({ revision: row.revision, key });
    function submit(event: FormEvent) { event.preventDefault(); form.post(`${base}/${row.id}/post`, { preserveScroll: true }); }
    return (
        <form onSubmit={submit} className={`${panel} space-y-3`}>
            <h2 className="font-semibold">Post approved reversal</h2>
            <p className="text-sm text-slate-600 dark:text-slate-300">Return {money(row.amount)} {row.currency_code} to {row.source?.name ?? 'the original source'} from {row.destination?.name ?? 'the original destination'}. This posts both compensating ledger entries together.</p>
            <p className="text-sm text-slate-500">Use the same action to retry an interrupted request. Posting rechecks the approval, accounts, date and period.</p>
            <Errors errors={form.errors} />
            <button className={button} disabled={form.processing}>{form.processing ? 'Posting…' : 'Post reversal'}</button>
        </form>
    );
}

const workflowLabels: [string, string][] = [
    ['created_by', 'Created by'], ['created_at', 'Created at'], ['last_edited_by', 'Last edited by'],
    ['submitted_by', 'Submitted by'], ['submitted_at', 'Submitted at'], ['approved_by', 'Approved by'],
    ['approved_at', 'Approved at'], ['posted_by', 'Posted by'], ['posted_at', 'Posted at'],
    ['cancelled_by', 'Cancelled by'], ['cancelled_at', 'Cancelled at'],
];

export default function Show(props: DetailProps) {
    const { reversal: row, capabilities, finance, workflow } = props;
    return (
        <Frame title={row.number} context={props}>
            <OriginalCard original={props.original} />
            <section className={panel}>
                <div className="flex flex-wrap items-center justify-between gap-3"><StatusBadge status={row.status} /><p className="text-sm text-slate-500">{row.document_date} · Revision {row.revision}</p></div>
                <dl className="mt-5 grid gap-5 md:grid-cols-3">
                    <div><dt className="text-sm text-slate-500">Returns to original source</dt><dd className="mt-1"><AccountLabel binding={row.source} /></dd></div>
                    <div><dt className="text-sm text-slate-500">Removes from original destination</dt><dd className="mt-1"><AccountLabel binding={row.destination} /></dd></div>
                    <div><dt className="text-sm text-slate-500">Reversal amount</dt><dd className="mt-1 break-all font-mono text-2xl font-semibold">{money(row.amount)} <span className="text-sm">{row.currency_code}</span></dd></div>
                </dl>
                <dl className="mt-5 space-y-4 border-t border-slate-200 pt-5 text-sm dark:border-slate-700">
                    <div><dt className="font-medium">Reason</dt><dd className="mt-1 whitespace-pre-wrap break-words text-slate-600 dark:text-slate-300">{row.reason}</dd></div>

                    {row.cancellation_reason && <div><dt className="font-medium">Cancellation reason</dt><dd className="mt-1 whitespace-pre-wrap break-words">{row.cancellation_reason}</dd></div>}
                </dl>
            </section>
            <section role="status" className={`rounded-xl border bg-white p-5 shadow-sm dark:bg-slate-900 ${finance.state === 'review' ? 'border-amber-400' : finance.state === 'verified' ? 'border-emerald-400' : 'border-slate-200 dark:border-slate-700'}`}>
                <h2 className="font-semibold">{finance.state === 'verified' ? 'Posting verified' : finance.state === 'review' ? 'Financial evidence needs review' : 'Not posted'}</h2>
                <p className="mt-1 text-sm">{finance.message}</p>
                <div className="mt-3 flex flex-wrap gap-4 text-sm">
                    {finance.journal_url && <Link className="underline" href={finance.journal_url}>View journal #{finance.journal_id}</Link>}
                    {props.bookLinks.map(link => <Link className="underline" key={link.label} href={link.url}>{link.label}</Link>)}
                </div>
            </section>
            {props.preview && <section className={`${panel} overflow-x-auto`}>
                <h2 className="font-semibold">Compensating entry preview</h2>
                <p className="mt-1 text-sm text-slate-500">Proposal only. Financial recognition is confirmed by the posting status above.</p>
                <table className="mt-3 w-full text-left text-sm"><caption className="sr-only">Reversal proposal</caption>
                    <thead><tr>{['GL account', 'Finance binding', 'Debit', 'Credit'].map(label => <th key={label} scope="col" className="p-2">{label}</th>)}</tr></thead>
                    <tbody>{props.preview.map(line => <tr key={line.cash_account_id}><td className="p-2">#{line.account_id}</td><td className="p-2">#{line.cash_account_id}</td><td className="p-2 font-mono">{money(line.debit)}</td><td className="p-2 font-mono">{money(line.credit)}</td></tr>)}</tbody>
                </table>
            </section>}
            <div className="flex flex-wrap items-start gap-3">
                {capabilities.edit && <Link className={secondary} href={`${base}/${row.id}/edit`}>Edit request</Link>}
                {capabilities.submit && <SimpleAction key={`submit:${row.id}:${row.revision}`} transfer={row} action="submit" label="Submit for approval" />}
                {capabilities.approve && <SimpleAction key={`approve:${row.id}:${row.revision}`} transfer={row} action="approve" label="Approve reversal" />}
            </div>
            {row.status === 'submitted' && <p className="text-sm text-slate-500">Approval requires someone who did not create, edit or submit this reversal request.</p>}
            {capabilities.post && <PostAction key={`post:${props.actorId}:${row.id}:${row.revision}`} context={props} />}
            {(capabilities.reject || capabilities.cancel) && <div className="grid gap-4 md:grid-cols-2">
                {capabilities.reject && <ReasonAction key={`reject:${row.id}:${row.revision}`} transfer={row} action="reject" />}
                {capabilities.cancel && <ReasonAction key={`cancel:${row.id}:${row.revision}`} transfer={row} action="cancel" />}
            </div>}
            <section className={panel}>
                <h2 className="font-semibold">Workflow record</h2>
                <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    {workflowLabels.filter(([field]) => workflow[field] !== null && workflow[field] !== undefined).map(([field, label]) => (
                        <div key={field}><dt className="text-slate-500">{label}</dt><dd className="mt-1 break-words">{field.endsWith('_by') ? `User #${workflow[field]}` : workflow[field]}</dd></div>
                    ))}
                </dl>
            </section>
            <section className={panel}>
                <h2 className="font-semibold">Audit history</h2>
                <ol className="mt-4 space-y-4 text-sm">
                    {props.history.map(event => <li key={event.id} className="border-l-2 border-slate-200 pl-4 dark:border-slate-600"><p className="font-medium capitalize">{event.action} · User #{event.actor_id}</p><p className="mt-1 text-xs text-slate-500">{event.occurred_at}</p>{event.reason && <p className="mt-1 whitespace-pre-wrap break-words">{event.reason}</p>}</li>)}
                </ol>
                {props.historyTruncated && <p className="mt-3 text-xs text-slate-500">Showing the latest 100 events.</p>}
            </section>
        </Frame>
    );
}
