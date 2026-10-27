import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Errors, Frame } from './Shared';
import { base, button, clearKey, input, keyScope, panel, secondary, stableKey, transferUrl } from './types';
import type { Binding, FormProps } from './types';

export default function Form(props: FormProps) {
    // The parent key refreshes the form only when its document/company/revision changes.
    return <TransferForm key={`${props.actorId}:${props.company?.id ?? 0}:${props.transfer?.id ?? 'new'}:${props.transfer?.revision ?? 0}`} {...props} />;
}

function TransferForm(props: FormProps) {
    const { transfer, company, bindings } = props;
    const scope = keyScope(props.actorId, company?.id ?? 0, 'create');
    const [creationKey] = useState(() => transfer ? '' : stableKey(scope, props.requestKey));
    const form = useForm({
        company_id: company?.id ?? 0, document_date: transfer?.document_date ?? props.today ?? '',
        source_cash_account_id: transfer ? String(transfer.source_cash_account_id) : '',
        destination_cash_account_id: transfer ? String(transfer.destination_cash_account_id) : '',
        amount: transfer?.amount ?? '', purpose: transfer?.purpose ?? '', notes: transfer?.notes ?? '',
    });
    const distinct = Boolean(form.data.source_cash_account_id && form.data.destination_cash_account_id && form.data.source_cash_account_id !== form.data.destination_cash_account_id);
    function submit(event: FormEvent) {
        event.preventDefault();
        // transform returns void in the installed Inertia version: call put/post on the form itself.
        form.transform(data => transfer ? { ...data, revision: transfer.revision } : { ...data, key: creationKey });
        const options = { preserveScroll: true, onSuccess: () => { if (!transfer) clearKey(scope, creationKey); } };
        if (transfer) form.put(`${base}/${transfer.id}`, options);
        else form.post(base, options);
    }
    function accountOptions(other: string) {
        return bindings.map((binding: Binding) => (
            <option key={binding.id} value={binding.id} disabled={!binding.selectable || String(binding.id) === other}>
                {binding.name} · {binding.kind} · {binding.currency_code}{!binding.selectable ? ' (unavailable)' : ''}
            </option>
        ));
    }
    return (
        <Frame title={transfer ? `Edit ${transfer.number}` : 'New Cash or Bank Transfer'} context={props}>
            <form onSubmit={submit} className={`${panel} space-y-5`}>
                <Errors errors={form.errors} />
                <div className="grid gap-5 text-sm md:grid-cols-2">
                    <label>Company<input className={input} value={company ? `${company.code} — ${company.name}` : ''} disabled /></label>
                    <label>Document date<input className={input} type="date" min="1900-01-01" max={props.today ?? undefined} required value={form.data.document_date} disabled={Boolean(transfer)} onChange={event => form.setData('document_date', event.target.value)} />{transfer && <span className="mt-1 block text-xs text-slate-500">The date stays fixed after numbering.</span>}</label>
                    <label>Source account<select className={input} required value={form.data.source_cash_account_id} onChange={event => form.setData('source_cash_account_id', event.target.value)}><option value="">Select source</option>{accountOptions(form.data.destination_cash_account_id)}</select></label>
                    <label>Destination account<select className={input} required value={form.data.destination_cash_account_id} onChange={event => form.setData('destination_cash_account_id', event.target.value)}><option value="">Select destination</option>{accountOptions(form.data.source_cash_account_id)}</select></label>
                    <label>Amount · {company?.currency_code}<input className={input} inputMode="decimal" autoComplete="off" maxLength={21} pattern="[0-9]+(\.[0-9]{1,2})?" title="Enter a positive amount with at most two decimal places" required value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} /></label>
                </div>
                <label className="block text-sm">Purpose<textarea className={input} rows={3} maxLength={2000} required value={form.data.purpose} onChange={event => form.setData('purpose', event.target.value)} /></label>
                <label className="block text-sm">Internal notes<textarea className={input} rows={3} maxLength={4000} value={form.data.notes} onChange={event => form.setData('notes', event.target.value)} /></label>
                <p className="text-sm text-slate-500">Saving creates a draft. Submission and independent approval happen before posting. The amount credits the source and debits the destination; fees or currency conversion need a separate workflow.</p>
                <div className="flex flex-wrap gap-3">
                    <button className={button} disabled={form.processing || !props.writable || !company || !distinct}>{form.processing ? 'Saving…' : 'Save draft'}</button>
                    <Link className={secondary} href={transfer ? `${base}/${transfer.id}` : transferUrl(company?.id)}>Cancel</Link>
                </div>
            </form>
        </Frame>
    );
}
