import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AdminLayout from '@/Layouts/admin-layout';

type Entry = { id: number; event: string; audience: string; channel: string; subject: string | null; body: string; enabled: boolean };
type Props = { templates: Entry[]; settings: { admin_email: string; admin_phone: string; automation_enabled: boolean }; canEdit: boolean };
const events = [
    { key: 'depot.submitted', label: 'Depot application submitted' },
    { key: 'depot.approved', label: 'Depot application approved' },
    { key: 'dealer.submitted', label: 'Dealer application submitted' },
    { key: 'dealer.approved', label: 'Dealer application approved' },
    { key: 'employee.created', label: 'Employee created' },
];
const input = 'mt-1 w-full rounded border border-slate-300 bg-white p-2 text-slate-900 dark:border-slate-600 dark:bg-slate-900 dark:text-white';
const panel = 'rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900';
function defaults(event: string, audience: string, channel: string) {
    const label = events.find(item => item.key === event)?.label ?? 'Business notification';
    const body = event === 'employee.created'
        ? 'Your employee record has been created. Please contact the administration team for further information.'
        : audience === 'admin'
            ? `${label}. Please review the application in the administration area.`
            : event.endsWith('.approved')
                ? 'Your application has been approved. Thank you for joining us.'
                : 'Your application has been received and is awaiting review. Thank you.';
    return { subject: channel === 'email' ? label : '', body };
}

export default function Edit({ templates, settings, canEdit }: Props) {
    const contacts = useForm({ admin_email: settings.admin_email ?? '', admin_phone: settings.admin_phone ?? '', automation_enabled: settings.automation_enabled });
    const initial = templates.find(item => item.event === 'depot.submitted' && item.audience === 'applicant' && item.channel === 'email');
    const initialDefaults = defaults('depot.submitted', 'applicant', 'email');
    const form = useForm({ event: 'depot.submitted', audience: 'applicant', channel: 'email', subject: initial?.subject ?? initialDefaults.subject, body: initial?.body ?? initialDefaults.body, enabled: initial?.enabled ?? false });

    function select(event: string, audience: string, channel: string) {
        const selected = templates.find(item => item.event === event && item.audience === audience && item.channel === channel);
        const fallback = defaults(event, audience, channel);
        form.setData({ event, audience, channel, subject: selected?.subject ?? fallback.subject, body: selected?.body ?? fallback.body, enabled: selected?.enabled ?? false });
        form.clearErrors();
    }
    function saveContacts(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        contacts.put('/admin/business-notifications/recipients', { preserveScroll: true });
    }
    function saveTemplate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.put('/admin/business-notifications/template', { preserveScroll: true });
    }

    return <AdminLayout><Head title="Business notifications" /><div className="space-y-5">
        <header><h1 className="text-2xl font-semibold">Business notification settings</h1><p className="mt-1 text-sm text-slate-500">Configure notifications for depot and dealer applications and employee creation.</p></header>
        <p className="rounded border border-amber-400 bg-amber-50 p-4 text-sm text-amber-950">Enable automation and the required templates to queue new events. The worker command sends pending messages. Existing records are not backfilled.</p>
        <div className="flex flex-wrap gap-4 text-sm"><Link className="underline" href="/admin/mail-settings">SMTP settings</Link><Link className="underline" href="/admin/sms-bd-settings">SMS.BD settings</Link><Link className="underline" href="/admin/notification-history">Delivery history</Link></div>
        <form onSubmit={saveContacts} className={`${panel} space-y-3`}><h2 className="font-semibold">Administrator contacts</h2>
            <p className="text-sm text-slate-500">These global administrator contacts receive enabled depot and dealer messages from all companies. Employee creation only notifies the employee.</p>
            <label className="flex gap-2 text-sm"><input type="checkbox" checked={contacts.data.automation_enabled} disabled={!canEdit} onChange={e => contacts.setData('automation_enabled', e.target.checked)} /> Enable automatic business notifications</label>
            <div className="grid gap-3 sm:grid-cols-2"><label>Email<input className={input} type="email" maxLength={255} value={contacts.data.admin_email} disabled={!canEdit} onChange={e => contacts.setData('admin_email', e.target.value)} /></label><label>Mobile<input className={input} type="tel" maxLength={32} value={contacts.data.admin_phone} disabled={!canEdit} onChange={e => contacts.setData('admin_phone', e.target.value)} /></label></div>
            {Object.entries(contacts.errors).map(([key, value]) => <p role="alert" className="text-sm text-red-700" key={key}>{value}</p>)}
            {canEdit && <button disabled={contacts.processing} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">Save contacts</button>}
        </form>
        <form onSubmit={saveTemplate} className={`${panel} space-y-3`}><h2 className="font-semibold">Message template</h2>
            <div className="grid gap-3 sm:grid-cols-3">
                <label>Event<select className={input} value={form.data.event} onChange={e => select(e.target.value, e.target.value === 'employee.created' ? 'employee' : 'applicant', form.data.channel)}>{events.map(item => <option key={item.key} value={item.key}>{item.label}</option>)}</select></label>
                <label>Recipient<select className={input} value={form.data.audience} onChange={e => select(form.data.event, e.target.value, form.data.channel)}>{form.data.event === 'employee.created' ? <option value="employee">Employee</option> : <><option value="applicant">Applicant</option><option value="admin">Administrator</option></>}</select></label>
                <label>Channel<select className={input} value={form.data.channel} onChange={e => select(form.data.event, form.data.audience, e.target.value)}><option value="email">Email</option><option value="sms">SMS</option></select></label>
            </div>
            {form.data.channel === 'email' && <label className="block">Subject<input className={input} required maxLength={255} disabled={!canEdit} value={form.data.subject} onChange={e => form.setData('subject', e.target.value)} /></label>}
            <label className="block">Message<textarea className={input} rows={7} required maxLength={form.data.channel === 'sms' ? 800 : 10000} disabled={!canEdit} value={form.data.body} onChange={e => form.setData('body', e.target.value)} /></label>
            <p className="text-sm text-slate-500">Placeholders: {'{name}, {business_name}, {reference}, {status}, {company_name}, {employee_number}'}. Plain text only. Do not include passwords, private identity or banking details.</p>
            <label className="flex gap-2 text-sm"><input type="checkbox" disabled={!canEdit} checked={form.data.enabled} onChange={e => form.setData('enabled', e.target.checked)} /> Enable this event and channel</label>
            {Object.entries(form.errors).map(([key, value]) => <p role="alert" className="text-sm text-red-700" key={key}>{value}</p>)}
            {canEdit && <button disabled={form.processing} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">Save template</button>}
        </form>
    </div></AdminLayout>;
}
