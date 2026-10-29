import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AdminLayout from '@/Layouts/admin-layout';

type RecordRow = { id: number; company_id: number; event: string; source_id: number; audience: string; channel: string; recipient: string; status: string; reason: string | null; attempts: number; provider_id: string | null; created_at: string };
type Props = { records: RecordRow[]; filters: { status: string; channel: string }; pagination: { current: number; last: number; total: number; previous: string | null; next: string | null }; canManage: boolean; errors?: Record<string, string> };

export default function Index({ records, filters, pagination, canManage, errors = {} }: Props) {
    const form = useForm({ ...filters });
    const [busy, setBusy] = useState<number | null>(null);
    function filter(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get('/admin/notification-history', form.data, { preserveScroll: true });
    }
    function action(id: number, value: string) {
        setBusy(id);
        router.post(`/admin/notification-history/${id}`, { action: value }, { preserveScroll: true, onFinish: () => setBusy(null) });
    }

    return <AdminLayout><Head title="Notification delivery history" /><div className="space-y-5">
        <header><h1 className="text-2xl font-semibold">Notification delivery history</h1><p className="mt-1 text-sm text-slate-500">Global mail administrators can review all companies. Recipients are masked; messages are encrypted in storage.</p></header>
        <p className="rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">Submitted means accepted by SMS.BD or the SMTP server, not delivered. Uncertain and interrupted sends cannot be retried here. Check provider records first.</p>
        <div className="flex flex-wrap gap-4 text-sm"><Link className="underline" href="/admin/business-notifications">Templates and automation</Link><Link className="underline" href="/admin/sms-bd-settings">SMS settings</Link><Link className="underline" href="/admin/mail-settings">SMTP settings</Link></div>
        <form onSubmit={filter} className="flex flex-wrap items-end gap-3 rounded-xl border bg-white p-4 text-sm">
            <label>Status<select className="ml-2 rounded border p-2" value={form.data.status} onChange={e => form.setData('status', e.target.value)}><option value="">All</option>{['pending', 'processing', 'submitted', 'blocked', 'failed', 'uncertain', 'skipped', 'cancelled'].map(status => <option key={status}>{status}</option>)}</select></label>
            <label>Channel<select className="ml-2 rounded border p-2" value={form.data.channel} onChange={e => form.setData('channel', e.target.value)}><option value="">All</option><option value="email">Email</option><option value="sms">SMS</option></select></label>
            <button className="rounded bg-slate-900 px-4 py-2 text-white">Filter</button>
        </form>
        {Object.entries(errors).map(([key, value]) => <p role="alert" key={key} className="text-sm text-red-700">{value}</p>)}
        <section className="overflow-x-auto rounded-xl border bg-white p-4"><table className="w-full text-left text-sm"><caption className="sr-only">Business notification delivery records</caption>
            <thead><tr>{['Record', 'Company and source', 'Event', 'Recipient', 'Status', 'Attempts', 'Actions'].map(label => <th key={label} className="p-3" scope="col">{label}</th>)}</tr></thead>
            <tbody>{records.map(row => <tr className="border-t" key={row.id}>
                <td className="whitespace-nowrap p-3">#{row.id}<p className="text-xs text-slate-500">{row.created_at}</p></td>
                <td className="p-3">Company #{row.company_id}<p className="text-xs">Source #{row.source_id}</p></td>
                <td className="p-3">{row.event}<p className="text-xs">{row.audience} · {row.channel}</p></td>
                <td className="p-3">{row.recipient}</td><td className="p-3"><strong>{row.status}</strong><p className="text-xs">{row.reason?.replaceAll('_', ' ')}</p>{row.provider_id && <p className="text-xs">Provider #{row.provider_id}</p>}</td>
                <td className="p-3">{row.attempts}</td><td className="p-3">{canManage && <div className="flex gap-3">{['blocked', 'failed'].includes(row.status) && <button className="underline disabled:opacity-50" disabled={busy !== null} onClick={() => action(row.id, 'retry')}>Retry</button>}{['pending', 'blocked', 'failed'].includes(row.status) && <button className="underline disabled:opacity-50" disabled={busy !== null} onClick={() => action(row.id, 'cancel')}>Cancel</button>}</div>}</td>
            </tr>)}</tbody>
        </table>{records.length === 0 && <p className="p-3">No records match these filters. Events created while automation was disabled are not backfilled.</p>}
        <nav aria-label="Delivery pages" className="mt-4 flex flex-wrap justify-between gap-3 text-sm"><span>{pagination.total} records · Page {pagination.current} of {pagination.last}</span><div className="flex gap-4">{pagination.previous && <Link href={pagination.previous}>Previous</Link>}{pagination.next && <Link href={pagination.next}>Next</Link>}</div></nav></section>
        <p className="text-sm text-slate-500">Skipped messages have no sendable snapshot. Fix missing contacts or disabled templates for future events; use the custom mail or SMS screen for a deliberate one-off message.</p>
    </div></AdminLayout>;
}
