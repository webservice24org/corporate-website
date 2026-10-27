import Exports from './Exports';
import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { button, input, money, panel } from '../CashTransfers/types';
import { Errors, Frame, Issues, Pages, Table } from '../Banking/Shared';
import { matchingIssue, requestKey, selectionTotal } from '../Banking/helpers';
import type { MatchChoice, MatchRow, ReconciliationProps } from '../Banking/types';
const path = '/erp/accounting/bank-reconciliations';
export default function Show(props: ReconciliationProps) {
    return <Workspace key={`${props.actorId}:${props.record.id}:${props.record.revision}`} {...props} />;
}
function Workspace(props: ReconciliationProps) {
    const report = props.report; const detail = `${path}/${props.record.id}`;
    const [statement, setStatement] = useState<MatchChoice[]>([]); const [book, setBook] = useState<MatchChoice[]>([]);
    const [key] = useState(() => requestKey(`${props.actorId}:${props.company?.id}:reconciliation:${props.record.id}:${props.record.revision}`, props.requestKey, 'match'));
    const form = useForm({ reason: '' }); const problem = matchingIssue(statement, book);
    const filters = { company_id: props.company?.id, ...props.filters,
        statement_page: props.pages.statement_rows?.current, book_page: props.pages.book_rows?.current, history_page: props.pages.history?.current };
    function match(event: FormEvent) {
        event.preventDefault(); if (problem || !props.capabilities.match) return;
        form.transform(data => ({ revision: props.record.revision, reason: data.reason, key,
            statement_allocations: statement.map(row => ({ id: row.id, amount: row.amount })), book_allocations: book.map(row => ({ id: row.id, amount: row.amount })) }));
        form.post(`${detail}/match`, { preserveScroll: true });
    }
    function choose(row: MatchRow, side: 'statement' | 'book') {
        const current = side === 'statement' ? statement : book; const update = side === 'statement' ? setStatement : setBook;
        if (current.some(item => item.id === row.id)) { update(current.filter(item => item.id !== row.id)); return; }
        if (current.length >= 100) return;
        const available = (side === 'statement' ? row.remaining : row.currently_available) ?? '0.00';
        update([...current, { id: row.id, direction: row.direction, available, amount: available }]);
    }
    return <Frame context={props} heading={`Reconciliation #${props.record.id}`}>
        <nav className="flex gap-4 text-sm" aria-label="Evidence"><Link className="underline" href={path}>Register</Link><Link className="underline" href={props.statementUrl}>Source statement</Link><Link className="underline" href={props.bookUrl}>Bankbook</Link><Link className="underline" href={`${detail}/readiness`}>Coverage review</Link><Link className="underline" href={`${detail}/finalization`}>Finalization and reopen</Link></nav>
        <Exports links={props.exportLinks} error={props.errors?.export} /><Issues issues={props.issues} /><section className="rounded-xl border border-amber-400 bg-amber-50 p-5 text-sm text-amber-950"><h2 className="font-semibold">{props.finalization?.state === 'finalized' ? 'Finalized reconciliation' : 'Working reconciliation'} · revision {props.record.revision} · {props.finalization?.state ?? 'working'}</h2><p className="mt-2">{props.finalization?.state === 'finalized' ? 'This cutoff has verified independent approval. Review its preserved evidence on the finalization page.' : report?.message ?? 'Verified figures are unavailable until the evidence is reviewed.'}</p><p className="mt-2">Matching creates no journal. Totals use the statement date cutoff and current matching state. Allocations in later statements can reduce availability today. A zero difference does not certify opening coverage.</p></section>
        {report && <>
            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{Object.entries(report.bridge).map(([label, amount]) => <div className={panel} key={label}><dt className="text-sm text-slate-500">{label.replaceAll('_', ' ')}</dt><dd className="mt-2 break-all font-mono text-xl">{money(amount)} <span className="text-sm">{report.currency_code}</span></dd></div>)}</dl>
            <div className={`${panel} flex flex-wrap gap-4`}><label className="text-sm">Direction<select className={input} value={props.filters.direction} onChange={event => router.get(detail, { ...filters, direction: event.target.value, statement_page: 1, book_page: 1 }, { preserveState: true, preserveScroll: true })}><option value="">All directions</option><option value="in">Money in</option><option value="out">Money out</option></select></label><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={props.filters.outstanding_only} onChange={event => router.get(detail, { ...filters, outstanding_only: event.target.checked ? 1 : 0, statement_page: 1, book_page: 1 }, { preserveState: true, preserveScroll: true })} />Outstanding through cutoff only</label><p className="self-center text-sm text-slate-500">Bridge totals cover all rows, independent of these filters.</p></div>
            <MovementTable caption="Statement movements" rows={report.statement_rows} side="statement" selected={statement} enabled={props.capabilities.match} choose={choose} /><Pages page={props.pages.statement_rows} path={detail} filters={filters} />
            <MovementTable caption="Book movements through cutoff" rows={report.book_rows} side="book" selected={book} enabled={props.capabilities.match} choose={choose} /><Pages page={props.pages.book_rows} path={detail} filters={filters} />
            {props.capabilities.match && <form className={`${panel} space-y-5`} onSubmit={match}><h2 className="text-lg font-semibold">Review explicit matching group</h2><Errors errors={form.errors} /><p className="text-sm">Selections stay in this page while browsing rows. Use one direction; no fee netting. Choose partial amounts where needed. Recognized adjustments must match their exact source bank movement.</p>
                <div className="grid gap-5 md:grid-cols-2"><Selection title="Statement" rows={statement} update={setStatement} /><Selection title="Book" rows={book} update={setBook} /></div>
                <label className="block text-sm">Matching reason<textarea className={input} required maxLength={2000} rows={2} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
                {problem && <p role="status" className="text-sm text-amber-700 dark:text-amber-300">{problem}</p>}<button className={button} disabled={form.processing || problem !== null}>Record matching group</button>
            </form>}
            <section className="space-y-4"><h2 className="text-xl font-semibold">Matching history</h2>{!report.history.length && <p className={panel}>No matching operations recorded.</p>}{report.history.map(operation => <article className={`${panel} space-y-3`} key={operation.id}><h3 className="font-semibold">#{operation.id} · {operation.kind} · {money(operation.total)} {report.currency_code} · {operation.direction}{operation.kind === 'match' ? operation.active ? ' · active' : ' · undone' : ` · compensates #${operation.target_match_id}`}</h3><p className="text-sm">Actor #{operation.actor_id} · {operation.occurred_at}</p><p className="whitespace-pre-wrap text-sm">{operation.reason}</p><ul className="flex flex-wrap gap-3 text-xs">{operation.legs.map(leg => <li key={`${leg.side}:${leg.id}`}>{leg.side} #{leg.id}: {money(leg.amount)}</li>)}</ul>{operation.kind === 'match' && operation.active && props.capabilities.unmatch && <Undo key={`${props.record.revision}:${operation.id}`} props={props} target={operation.id} />}</article>)}<Pages page={props.pages.history} path={detail} filters={filters} /></section>
        </>}
    </Frame>;
}
function MovementTable({ caption, rows, side, selected, enabled, choose }: { caption: string; rows: MatchRow[]; side: 'statement' | 'book'; selected: MatchChoice[]; enabled: boolean; choose: (row: MatchRow, side: 'statement' | 'book') => void }) {
    return <Table caption={caption} headers={['Choose', 'ID / date', 'Direction', 'Amount', 'Matched at cutoff', 'Outstanding at cutoff', 'Available now', 'Allocated later']}>
        {rows.map(row => { const available = (side === 'statement' ? row.remaining : row.currently_available) ?? '0.00'; const checked = selected.some(item => item.id === row.id); return <tr key={row.id}><td className="p-3"><input type="checkbox" aria-label={`Select ${side} movement ${row.id}`} disabled={!enabled || available === '0.00' || (!checked && selected.length >= 100)} checked={checked} onChange={() => choose(row, side)} /></td><td className="p-3 whitespace-nowrap">#{row.id}<br />{row.date}<span className="mt-1 block max-w-xs whitespace-normal text-xs text-slate-500">{row.reference}</span>{row.description && <span className="mt-1 block max-w-xs whitespace-pre-wrap text-xs">{row.description}</span>}{row.source_url && <Link className="mr-3 text-xs underline" href={row.source_url}>Source</Link>}{row.journal_url && <Link className="text-xs underline" href={row.journal_url}>Journal</Link>}</td><td className="p-3">{row.direction}</td><td className="p-3 whitespace-nowrap font-mono">{money(row.amount)}</td><td className="p-3 font-mono">{money(row.matched ?? row.matched_through_cutoff ?? '0.00')}</td><td className="p-3 font-mono">{money(row.remaining ?? row.outstanding_through_cutoff ?? '0.00')}</td><td className="p-3 font-mono">{money(available)}</td><td className="p-3 font-mono">{money(row.allocated_to_later_statements ?? '0.00')}</td></tr>; })}
        {!rows.length && <tr><td colSpan={8} className="p-6 text-center text-slate-500">No movements for these filters.</td></tr>}
    </Table>;
}
function Selection({ title, rows, update }: { title: string; rows: MatchChoice[]; update: (rows: MatchChoice[]) => void }) {
    return <section className="space-y-3"><h3 className="font-semibold">{title} · {rows.length}/100 · {money(selectionTotal(rows))}</h3>{rows.map(row => <div key={row.id} className="flex items-end gap-3"><label className="flex-1 text-sm">#{row.id} · {row.direction} · Max {money(row.available)}<input className={input} type="text" inputMode="decimal" required value={row.amount} onChange={event => update(rows.map(item => item.id === row.id ? { ...item, amount: event.target.value } : item))} /></label><button type="button" className="pb-2 text-sm underline" onClick={() => update(rows.filter(item => item.id !== row.id))}>Remove</button></div>)}</section>;
}
function Undo({ props, target }: { props: ReconciliationProps; target: number }) {
    const [key] = useState(() => requestKey(`${props.actorId}:${props.company?.id}:reconciliation:${props.record.id}:${props.record.revision}`, props.requestKey, 'undo', String(target)));
    const form = useForm({ revision: props.record.revision, target_match_id: target, reason: '', key });
    function submit(event: FormEvent) { event.preventDefault(); form.post(`${path}/${props.record.id}/unmatch`, { preserveScroll: true }); }
    return <form className="space-y-3 border-t pt-4" onSubmit={submit}><Errors errors={form.errors} /><p className="text-sm">Undo restores every allocation in this group and keeps its original history. It creates no journal.</p><label className="block text-sm">Undo reason<textarea required maxLength={2000} className={input} rows={2} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label><button className={button} disabled={form.processing}>Undo whole group #{target}</button></form>;
}
