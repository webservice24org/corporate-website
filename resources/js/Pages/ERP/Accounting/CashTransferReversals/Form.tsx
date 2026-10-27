import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Errors, Frame, OriginalCard } from './Shared';
import { base, button, clearKey, input, keyScope, panel, secondary, stableKey } from './types';
import type { FormProps } from './types';

export default function Form(props: FormProps) {
    return <ReversalForm key={`${props.actorId}:${props.company?.id ?? 0}:${props.original?.id ?? 0}:${props.reversal?.id ?? 'new'}:${props.reversal?.revision ?? 0}`} {...props} />;
}

function ReversalForm(props: FormProps) {
    const { original, reversal } = props;
    const scope = keyScope(props.actorId, props.company?.id ?? 0, `create:${original?.id ?? 0}`);
    const [key] = useState(() => reversal ? '' : stableKey(scope, props.requestKey));
    const form = useForm({ document_date: reversal?.document_date ?? props.today ?? '', reason: reversal?.reason ?? '' });
    function submit(event: FormEvent) {
        event.preventDefault();
        if (!original) return;
        if (reversal) {
            form.transform(data => ({ ...data, revision: reversal.revision }));
            form.put(`${base}/${reversal.id}`, { preserveScroll: true });
        } else {
            form.transform(data => ({ ...data, transfer_id: original.id, key }));
            form.post(base, { preserveScroll: true, onSuccess: () => clearKey(scope, key) });
        }
    }
    return (
        <Frame title={reversal ? `Edit ${reversal.number}` : 'Prepare Transfer Reversal'} context={props}>
            <OriginalCard original={original} />
            <form onSubmit={submit} className={`${panel} space-y-5`}>
                <p className="text-sm text-slate-500">Only the date and reason can be changed. Saving a draft does not post money.</p>
                <label className="block text-sm font-medium">Reversal date<input type="date" required min={original?.document_date ?? '1900-01-01'} max={props.today ?? undefined} className={input} value={form.data.document_date} onChange={event => form.setData('document_date', event.target.value)} /></label>
                <label className="block text-sm font-medium">Reason<textarea required rows={4} maxLength={2000} className={input} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
                <Errors errors={{ ...props.errors, ...form.errors }} />
                <div className="flex flex-wrap gap-3">
                    <button className={button} disabled={form.processing || !original || !props.writable}>{form.processing ? 'Saving…' : 'Save draft'}</button>
                    <Link className={secondary} href={reversal ? `${base}/${reversal.id}` : base}>Cancel</Link>
                </div>
                {!reversal && <p className="text-xs text-slate-500">Retry an interrupted save with the same form. After a full reload, re-enter the same date and reason before retrying.</p>}
            </form>
        </Frame>
    );
}
