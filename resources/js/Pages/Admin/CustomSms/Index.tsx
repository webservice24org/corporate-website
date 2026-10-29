import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AdminLayout from '@/Layouts/admin-layout';

type Props = { enabled: boolean; canSend: boolean };

export default function Index({ enabled, canSend }: Props) {
    const form = useForm({ phone: '', message: '' });
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/admin/custom-sms', { preserveScroll: true });
    }

    return <AdminLayout><Head title="Send custom SMS" /><div className="mx-auto max-w-3xl space-y-5">
        <header><h1 className="text-2xl font-semibold">Send custom SMS</h1><p className="mt-1 text-sm text-slate-500">Send one message to one Bangladeshi mobile number.</p></header>
        {!enabled && <p role="status" className="rounded border border-amber-400 bg-amber-50 p-4 text-amber-950">SMS delivery is disabled in server configuration.</p>}
        <p className="text-sm text-slate-600">Provider acceptance does not confirm delivery. If the result is uncertain, check the provider before trying again.</p>
        <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
            <label className="block text-sm font-medium">Mobile number<input type="tel" required maxLength={32} value={form.data.phone} onChange={e => form.setData('phone', e.target.value)} disabled={!canSend || !enabled} placeholder="017XXXXXXXX" className="mt-1 w-full rounded border border-slate-300 p-2" /></label>
            <label className="block text-sm font-medium">Message<textarea required maxLength={800} rows={6} value={form.data.message} onChange={e => form.setData('message', e.target.value)} disabled={!canSend || !enabled} className="mt-1 w-full rounded border border-slate-300 p-2" /></label>
            {Object.entries(form.errors).map(([key, value]) => <p role="alert" className="text-sm text-red-700" key={key}>{value}</p>)}
            {canSend && <button disabled={form.processing || !enabled} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">{form.processing ? 'Submitting…' : 'Send SMS'}</button>}
        </form>
        <div className="flex flex-wrap gap-4 text-sm"><Link href="/admin/sms-bd-settings" className="underline">SMS.BD settings</Link><Link href="/admin/business-notifications" className="underline">Notification templates and contacts</Link></div>
    </div></AdminLayout>;
}
