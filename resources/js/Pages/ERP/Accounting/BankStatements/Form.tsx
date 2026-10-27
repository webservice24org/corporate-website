import { Link, useForm } from '@inertiajs/react';
import axios from 'axios';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Errors, Frame, Rows } from './Shared';
import { base, button, clearKey, emptyEntry, input, money, panel, secondary, stableKey } from './types';
import type { Entry, FormProps, Metadata, Preview } from './types';
type Data = { mode: 'csv' | 'manual'; metadata: Metadata; lines: Entry[]; file: File | null };

export default function Form(props: FormProps) {
    return <StatementForm key={`${props.actorId}:${props.company?.id ?? 0}`} {...props} />;
}
function StatementForm(props: FormProps) {
    const scope = `erp:bank-statement:${props.actorId}:${props.company?.id ?? 0}:create`;
    const [key] = useState(() => stableKey(scope, props.requestKey));
    const form = useForm<Data>({ mode: props.canImport ? 'csv' : 'manual', metadata: { company_id: String(props.company?.id ?? ''), cash_account_id: '', statement_reference: '', currency_code: props.company?.currency_code ?? '', starts_on: props.today ?? '', ends_on: props.today ?? '', opening_balance: '', closing_balance: '' }, lines: [], file: null });
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [previewPage, setPreviewPage] = useState(1);
    const busy = form.processing || previewing;
    function invalidate() { setPreview(null); setPreviewPage(1); setErrors({}); form.clearErrors(); }
    function metadata(field: keyof Metadata, value: string) { invalidate(); form.setData('metadata', { ...form.data.metadata, [field]: value }); }
    function line(index: number, field: keyof Entry, value: string) { invalidate(); form.setData('lines', form.data.lines.map((row, i) => i === index ? { ...row, [field]: value } : row)); }
    async function review() {
        setErrors({}); setPreview(null); setPreviewing(true); setPreviewPage(1);
        try {
            let body: (Data & { key: string }) | FormData = { ...form.data, key, lines: form.data.mode === 'manual' ? form.data.lines : [], file: null };
            if (form.data.mode === 'csv') {
                const upload = new FormData(); upload.append('mode', 'csv'); upload.append('key', key);
                Object.entries(form.data.metadata).forEach(([name, value]) => upload.append(`metadata[${name}]`, value));
                if (form.data.file) upload.append('file', form.data.file);
                body = upload;
            }
            const response = await axios.post<Preview>(`${base}/preview`, body, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            setPreview(response.data);
        } catch (error) {
            if (axios.isAxiosError<{ errors?: Record<string, string[]> }>(error) && error.response?.status === 422 && error.response.data.errors) {
                setErrors(Object.fromEntries(Object.entries(error.response.data.errors).map(([field, messages]) => [field, messages.join(' ')])));
            } else { setErrors({ statement: 'Preview could not be completed. Check your connection and permissions; reload if your session has expired.' }); }
        } finally { setPreviewing(false); }
    }
    function submit(event: FormEvent) {
        event.preventDefault();
        if (!preview) { setErrors({ statement: 'Preview the current statement before recording it.' }); return; }
        form.transform(data => ({ ...data, file: data.mode === 'csv' ? data.file : null, lines: data.mode === 'manual' ? data.lines : [], key, preview_hash: preview.preview_hash }));
        form.post(`${base}/${form.data.mode === 'csv' ? 'import' : 'manual'}`, { preserveScroll: true, onSuccess: () => clearKey(scope, key) });
    }
    const previewLast = Math.max(1, Math.ceil((preview?.lines.length ?? 0) / 50));
    return <Frame title="Import or Enter Bank Statement" context={props}>
        <form onSubmit={submit} className="space-y-5">
            <fieldset disabled={busy} className={`${panel} space-y-4`}>
                <legend className="sr-only">Statement account and balances</legend>
                <p className="text-sm text-slate-500">Confirm the bank account against the original statement. The template does not authenticate an external bank account.</p>
                <div className="grid gap-4 text-sm md:grid-cols-2 lg:grid-cols-3">
                    <label>Entry method<select className={input} value={form.data.mode} onChange={event => { invalidate(); form.setData('mode', event.target.value === 'csv' ? 'csv' : 'manual'); }}>{props.canImport && <option value="csv">CSV import</option>}{props.canCreate && <option value="manual">Manual entry</option>}</select></label>
                    <label>Bank account<select required className={input} value={form.data.metadata.cash_account_id} onChange={event => metadata('cash_account_id', event.target.value)}><option value="">Select bank account</option>{props.banks.filter(bank => bank.currency_code === props.company?.currency_code).map(bank => <option key={bank.id} value={bank.id}>{bank.name} · {bank.role}{bank.status !== 'active' ? ' (inactive; historical evidence)' : ''}</option>)}</select></label>
                    <label>Statement reference<input required maxLength={80} className={input} value={form.data.metadata.statement_reference} onChange={event => metadata('statement_reference', event.target.value)} /></label>
                    <label>Period starts<input required type="date" min="1900-01-01" max={props.today ?? undefined} className={input} value={form.data.metadata.starts_on} onChange={event => metadata('starts_on', event.target.value)} /></label>
                    <label>Period ends<input required type="date" min={form.data.metadata.starts_on || '1900-01-01'} max={props.today ?? undefined} className={input} value={form.data.metadata.ends_on} onChange={event => metadata('ends_on', event.target.value)} /></label>
                    <label>Currency<input disabled className={input} value={form.data.metadata.currency_code} /></label>
                    <label>Opening bank balance<input required inputMode="decimal" className={input} placeholder="0.00 or -25.25" value={form.data.metadata.opening_balance} onChange={event => metadata('opening_balance', event.target.value)} /></label>
                    <label>Closing bank balance<input required inputMode="decimal" className={input} value={form.data.metadata.closing_balance} onChange={event => metadata('closing_balance', event.target.value)} /></label>
                </div>
                <p className="text-sm text-slate-500">Positive balances are bank assets; negative balances are overdrafts. Money in increases the balance; money out decreases it. Use exact decimal text without commas, currency symbols or scientific notation.</p>
                {form.data.mode === 'csv' && <div className="space-y-3"><label className="block text-sm">UTF-8 CSV · maximum 512 KiB / 1,000 rows<input type="file" accept=".csv,text/csv" className={input} onChange={event => { invalidate(); form.setData('file', event.target.files?.[0] ?? null); }} /></label><a className="text-sm underline" href={`${base}/template`}>Download CSV template</a><p className="text-xs text-slate-500">Columns: date, value_date, transaction_id, reference, description, money_in, money_out, balance. Dates: YYYY-MM-DD. Keep dates ascending; preserve the bank’s order within a date. Optional balances are checked against calculated balances.</p></div>}
            </fieldset>
            {form.data.mode === 'manual' && <fieldset disabled={busy} className={`${panel} space-y-4`}><legend className="sr-only">Manual statement rows</legend><h2 className="font-semibold">Manual statement rows · {form.data.lines.length} / 1,000</h2><p className="text-sm text-slate-500">Enter transactions in statement order. Leave all rows empty only for a genuine no-movement statement with equal opening and closing balances.</p><div className="space-y-4">{form.data.lines.map((row, index) => <section key={index} className="rounded-lg border border-slate-200 p-4 dark:border-slate-700"><div className="mb-3 flex justify-between gap-3"><h3 className="text-sm font-semibold">Row {index + 1}</h3><button type="button" className="text-sm underline" onClick={() => { invalidate(); form.setData('lines', form.data.lines.filter((_, i) => i !== index)); }}>Remove row</button></div><div className="grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-4">{(['date', 'value_date', 'transaction_id', 'reference', 'description', 'money_in', 'money_out', 'balance'] as (keyof Entry)[]).map(field => <label key={field}>{field.replaceAll('_', ' ')}<input className={input} type={field === 'date' || field === 'value_date' ? 'date' : 'text'} inputMode={['money_in', 'money_out', 'balance'].includes(field) ? 'decimal' : undefined} maxLength={field === 'description' ? 500 : field === 'reference' ? 160 : field === 'transaction_id' ? 80 : undefined} value={row[field]} onChange={event => line(index, field, event.target.value)} /></label>)}</div></section>)}</div><button type="button" disabled={form.data.lines.length >= 1000} className={secondary} onClick={() => { invalidate(); form.setData('lines', [...form.data.lines, emptyEntry(form.data.lines[form.data.lines.length - 1]?.date ?? form.data.metadata.starts_on)]); }}>Add transaction row</button></fieldset>}
            <Errors errors={{ ...props.errors, ...form.errors, ...errors }} />
            <div><button type="button" disabled={busy} className={secondary} onClick={() => void review()}>{previewing ? 'Checking…' : 'Preview and validate statement'}</button></div>
            {preview && <section className={`${panel} space-y-4`}>
                <h2 className="font-semibold">Validated preview · {preview.metadata.statement_reference}</h2>
                <p className="text-sm">{preview.metadata.starts_on} → {preview.metadata.ends_on} · {preview.totals.row_count} rows · Opening {money(preview.metadata.opening_balance)} + In {money(preview.totals.money_in)} − Out {money(preview.totals.money_out)} = Closing {money(preview.metadata.closing_balance)} {preview.metadata.currency_code}</p>
                {preview.warnings.length > 0 && <ul role="status" className="space-y-1 rounded-lg bg-amber-50 p-3 text-sm text-amber-950">{preview.warnings.map(warning => <li key={warning}>{warning}</li>)}</ul>}
                <Rows rows={preview.lines.slice((previewPage - 1) * 50, previewPage * 50)} />
                {previewLast > 1 && <nav aria-label="Preview pages" className="flex gap-4 text-sm"><button type="button" disabled={previewPage <= 1} className="underline disabled:opacity-50" onClick={() => setPreviewPage(previewPage - 1)}>Previous</button><span>{previewPage} / {previewLast} · Totals include all rows</span><button type="button" disabled={previewPage >= previewLast} className="underline disabled:opacity-50" onClick={() => setPreviewPage(previewPage + 1)}>Next</button></nav>}
                <p className="text-sm text-slate-500">Confirm the account and every row. Recorded evidence cannot be edited or deleted here. Retry an interrupted save with the same input; after reload, reselect the same file or re-enter the same manual data.</p>
                {preview.existing_url ? <Link className={button} href={preview.existing_url}>Open already recorded statement</Link> : <button className={button} disabled={busy}>{form.processing ? 'Recording…' : 'Record immutable statement'}</button>}
            </section>}
        </form>
    </Frame>;
}
