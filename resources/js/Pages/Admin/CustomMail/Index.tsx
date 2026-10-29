import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AdminLayout from '@/Layouts/admin-layout';

type Props = { template: { subject: string; message: string }; canEdit: boolean };
const input = 'mt-1 w-full rounded border border-slate-300 bg-white p-2 text-slate-900';

export default function Index({ template, canEdit }: Props) {
    const form = useForm({ email: '', subject: template.subject, message: template.message });
    function send(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/admin/custom-mail/send', { preserveScroll: true });
    }
    function saveTemplate() {
        form.transform(data => ({ subject: data.subject, message: data.message }));
        form.put('/admin/custom-mail/template', { preserveScroll: true, onFinish: () => form.transform(data => data) });
    }

    return <AdminLayout><Head title="Send custom email" /><div className="mx-auto max-w-3xl space-y-5">
        <header><h1 className="text-2xl font-semibold">Send custom email</h1><p className="mt-1 text-sm text-slate-500">Write one email or save its subject and message as a reusable template.</p></header>
        <p className="text-sm text-slate-600">The email uses the active SMTP settings. Submission to the mail server does not guarantee final delivery.</p>
        <form onSubmit={send} className="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
            <label className="block text-sm font-medium">Recipient email<input className={input} type="email" required maxLength={255} value={form.data.email} onChange={e => form.setData('email', e.target.value)} disabled={!canEdit} /></label>
            <label className="block text-sm font-medium">Subject<input className={input} required maxLength={255} value={form.data.subject} onChange={e => form.setData('subject', e.target.value)} disabled={!canEdit} /></label>
            <label className="block text-sm font-medium">Message<textarea className={input} required maxLength={10000} rows={9} value={form.data.message} onChange={e => form.setData('message', e.target.value)} disabled={!canEdit} /></label>
            {Object.entries(form.errors).map(([key, value]) => <p role="alert" className="text-sm text-red-700" key={key}>{value}</p>)}
            {canEdit && <div className="flex flex-wrap gap-3"><button disabled={form.processing} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">{form.processing ? 'Please wait…' : 'Send email'}</button><button type="button" disabled={form.processing} onClick={saveTemplate} className="rounded border border-slate-300 px-4 py-2 disabled:opacity-50">Save template</button></div>}
        </form>
        <div className="flex flex-wrap gap-4 text-sm"><Link className="underline" href="/admin/mail-settings">SMTP settings</Link><Link className="underline" href="/admin/business-notifications">Notification templates</Link><Link className="underline" href="/admin/custom-sms">Custom SMS</Link></div>
    </div></AdminLayout>;
}
