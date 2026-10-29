import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AdminLayout from '@/Layouts/admin-layout';

type Props = { settings: { enabled: boolean; sender_id: string; has_key: boolean; source: string }; canEdit: boolean };
const input = 'mt-1 w-full rounded border border-slate-300 bg-white p-2 text-slate-900';

export default function Edit({ settings, canEdit }: Props) {
    const form = useForm({ api_key: '', sender_id: settings.sender_id, enabled: settings.enabled });
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.put('/admin/sms-bd-settings', { preserveScroll: true, onSuccess: () => form.setData('api_key', '') });
    }

    return <AdminLayout><Head title="SMS.BD settings" /><div className="mx-auto max-w-3xl space-y-5">
        <header><h1 className="text-2xl font-semibold">SMS.BD settings</h1><p className="mt-1 text-sm text-slate-500">Manage the API connection used by the single-SMS screen.</p></header>
        <p className="text-sm">API key: {settings.has_key ? 'Configured' : 'Not configured'} · Current source: {settings.source === 'admin' ? 'Admin settings' : 'Server environment'}</p>
        {settings.source === 'environment' && <p className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">Saving here switches to the admin settings. To enable SMS, enter the key in this form even if one is configured in the environment.</p>}
        <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
            <label className="block text-sm font-medium">API key<input type="password" autoComplete="new-password" maxLength={1000} className={input} value={form.data.api_key} onChange={e => form.setData('api_key', e.target.value)} disabled={!canEdit} /><span className="mt-1 block text-xs text-slate-500">Leave blank to keep the saved admin key. It is never displayed.</span></label>
            <label className="block text-sm font-medium">Approved sender ID <span className="font-normal">(optional)</span><input maxLength={80} className={input} value={form.data.sender_id} onChange={e => form.setData('sender_id', e.target.value)} disabled={!canEdit} /></label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.enabled} onChange={e => form.setData('enabled', e.target.checked)} disabled={!canEdit} /> Enable SMS delivery</label>
            {Object.entries(form.errors).map(([key, value]) => <p role="alert" className="text-sm text-red-700" key={key}>{value}</p>)}
            {canEdit && <button disabled={form.processing} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">Save SMS settings</button>}
        </form>
        <div className="flex gap-4 text-sm"><Link href="/admin/custom-sms" className="underline">Custom SMS</Link><Link href="/admin/business-notifications" className="underline">Notification templates</Link></div>
    </div></AdminLayout>;
}
