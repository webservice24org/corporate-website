import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { button, input, money, panel, secondary } from '../CashTransfers/types';
import { base } from './types';
import type { FormProps } from './types';
import { Errors, Frame, Issues, url } from './Shared';
import { forgetRequestKey, requestKey } from './helpers';
export default function Prepare(props: FormProps) {
    return <PrepareForm key={`${props.actorId}:${props.company?.id}:${props.record?.id ?? props.original?.id ?? 0}:${props.record?.revision ?? 0}:${props.selection?.statement_id ?? 0}`} {...props} />;
}
function PrepareForm(props: FormProps) {
    const path = base(props.module); const edit = props.mode === 'edit'; const refund = props.mode === 'refund';
    const scope = `${props.actorId}:${props.company?.id}:${props.module}:${props.record?.id ?? props.original?.id ?? 0}:${props.record?.revision ?? 0}:${props.selection?.statement_id ?? 0}`;
    const [key] = useState(() => requestKey(scope, props.requestKey, refund ? 'refund' : 'create'));
    const form = useForm({ statement_id: props.selection?.statement_id ?? '', statement_line_id: '', kind: 'charge', counter_account_id: '', document_date: props.record?.document_date ?? '', reason: props.record?.reason ?? '' });
    const selected = props.sources?.find(row => String(row.id) === form.data.statement_line_id);
    const kind = selected ? (selected.direction === 'out' ? 'charge' : 'interest') : form.data.kind;
    const canSave = props.writable && props.issues.length === 0 && (edit || (props.module === 'reconciliations' ? !!props.selection?.statement_id && !props.existingUrl : !!selected?.selectable));
    function stage(values: Record<string, string>) {
        router.get(refund ? `${path}/${props.original?.id}/refund` : `${path}/create`, { company_id: props.company?.id, ...values });
    }
    function submit(event: FormEvent) {
        event.preventDefault(); if (!canSave) return;
        if (edit) {
            form.transform(data => ({ revision: props.record?.revision, document_date: data.document_date, reason: data.reason }));
            form.put(`${path}/${props.record?.id}`);
        } else if (props.module === 'reconciliations') {
            form.transform(data => ({ statement_id: props.selection?.statement_id, reason: data.reason, key })); form.post(path, { onSuccess: () => forgetRequestKey(scope, 'create', key) });
        } else {
            form.transform(data => refund ? { statement_line_id: data.statement_line_id, document_date: data.document_date, reason: data.reason, key } : { statement_line_id: data.statement_line_id, kind, counter_account_id: data.counter_account_id, document_date: data.document_date, reason: data.reason, key });
            form.post(refund ? `${path}/${props.original?.id}/refund` : path, { onSuccess: () => forgetRequestKey(scope, refund ? 'refund' : 'create', key) });
        }
    }
    return <Frame context={props} heading={edit ? `Edit adjustment #${props.record?.id}` : refund ? `Refund adjustment #${props.original?.id}` : props.module === 'adjustments' ? 'Prepare bank adjustment' : 'Open reconciliation workspace'}>
        <Link className="text-sm underline" href={path}>Back to register</Link><Issues issues={props.issues} /><Errors errors={form.errors} />
        {!edit && <section className={`${panel} grid gap-4 md:grid-cols-3`} aria-label="Source selection">
            <label className="text-sm">Company<select className={input} disabled={refund} value={props.company?.id ?? ''} onChange={event => router.get(`${path}/create`, { company_id: event.target.value })}><option value="">Select company</option>{props.companies.map(company => <option key={company.id} value={company.id}>{company.code} — {company.name}</option>)}</select></label>
            <label className="text-sm">Bank<select className={input} disabled={!props.company || refund} value={props.selection?.cash_account_id ?? ''} onChange={event => stage({ cash_account_id: event.target.value })}><option value="">Select bank</option>{props.banks.map(bank => <option key={bank.id} value={bank.id}>{bank.name} · {bank.currency_code} · {bank.status}</option>)}</select></label>
            <label className="text-sm">Statement<select className={input} disabled={!props.selection?.cash_account_id} value={props.selection?.statement_id ?? ''} onChange={event => stage({ cash_account_id: props.selection?.cash_account_id ?? '', statement_id: event.target.value })}><option value="">Select statement</option>{props.statements?.map(statement => <option key={statement.id} value={statement.id}>{statement.label}</option>)}</select><span className="mt-1 block text-xs text-slate-500">Latest 100 statements. Existing records retain their original source.</span></label>
        </section>}
        {props.existingUrl ? <p className={panel}>This statement already has a workspace. <Link className="underline" href={props.existingUrl}>Open existing reconciliation</Link></p> : <form className={`${panel} space-y-5`} onSubmit={submit}>
            {props.module === 'adjustments' && !edit && <label className="block text-sm">Statement movement<select className={input} required value={form.data.statement_line_id} onChange={event => {
                const source = props.sources?.find(row => String(row.id) === event.target.value);
                form.setData(data => ({ ...data, statement_line_id: event.target.value, counter_account_id: '', document_date: source ? (props.original?.document_date && props.original.document_date > source.date ? props.original.document_date : source.date) : '', kind: source?.direction === 'in' ? 'interest' : 'charge' }));
            }}><option value="">Select movement</option>{props.sources?.map(source => <option key={source.id} value={source.id} disabled={!source.selectable}>#{source.id} · {source.date} · Money {source.direction} · {money(source.amount)} · {source.reference || source.description.slice(0, 60)}{source.selectable ? '' : ' · unavailable'}</option>)}</select></label>}
            {props.module === 'adjustments' && <>
                <div className="rounded-lg bg-slate-50 p-4 text-sm dark:bg-slate-800">{edit ? `${props.record?.kind} · ${props.record?.amount} ${props.record?.currency_code} · Counter account #${props.record?.counter_account_id}` : refund ? `Full opposite movement only · ${props.original?.amount} ${props.original?.currency_code} · Original counter #${props.original?.counter_account_id}` : `${kind === 'charge' ? 'Bank charge' : 'Bank interest'} · ${selected ? money(selected.amount) : 'Select a movement'}`}. Source, amount and accounts are fixed after preparation.</div>
                {!edit && !refund && <label className="block text-sm">Counter GL account<select className={input} required value={form.data.counter_account_id} onChange={event => form.setData('counter_account_id', event.target.value)}><option value="">Select classified posting account</option>{props.counters?.filter(counter => counter.kind === kind).map(counter => <option key={counter.id} value={counter.id}>{counter.code} — {counter.name}</option>)}</select></label>}
                <label className="block text-sm">Posting date<input className={input} type="date" required min={selected?.date ?? '1900-01-01'} max={selected && props.today ? (selected.ends_on < props.today ? selected.ends_on : props.today) : props.today ?? undefined} value={form.data.document_date} onChange={event => form.setData('document_date', event.target.value)} /></label>
            </>}
            <label className="block text-sm">Reason<textarea className={input} maxLength={2000} required rows={3} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
            <p className="text-sm text-slate-500">{props.module === 'adjustments' ? 'Preparation creates a draft. Submission, independent approval and posting are separate actions. Posting does not automatically match the statement.' : 'Opening this workspace creates no journal or match. Select explicit amounts after reviewing statement and book evidence.'}</p>
            <div className="flex gap-3"><button className={button} disabled={!canSave || form.processing}>{edit ? 'Save draft' : props.module === 'adjustments' ? 'Prepare draft' : 'Open workspace'}</button><Link className={secondary} href={edit ? `${path}/${props.record?.id}` : path}>Cancel</Link></div>
        </form>}
    </Frame>;
}
