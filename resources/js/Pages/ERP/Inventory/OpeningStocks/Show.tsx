import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Pencil, Trash2, CheckCircle } from 'lucide-react';
import ERPLayout from '@/Layouts/erp-layout';
import { useAuthorization } from '@/hooks/useAuthorization';
import DeleteConfirmDialog from '@/Components/Common/DeleteConfirmDialog';
import { AlertDialogContent, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogFooter, AlertDialogCancel } from '@/Components/ui/alert-dialog';
import { base, button, panel, number, date, Status, type OpeningStock, type StockItem } from './shared';

type Props = { openingStock: OpeningStock & { items: StockItem[] } };
export default function Show({ openingStock: stock }: Props) {
    const { can } = useAuthorization();
    const [deleting, setDeleting] = useState(false);
    const [posting, setPosting] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const draft = stock.status === 'draft';
    function submit(action: 'post' | 'delete') {
        if (processing || !draft || !can(`erp.opening-stocks.${action}`)) return;
        setProcessing(true); setErrors({});
        const options = {
            preserveScroll: true,
            onSuccess: () => { setPosting(false); setDeleting(false); },
            onError: (messages: Record<string, string>) => { setErrors(messages); setPosting(false); setDeleting(false); },
            onFinish: () => setProcessing(false),
        };
        if (action === 'post') router.post(`${base}/${stock.id}/post`, {}, options);
        else router.delete(`${base}/${stock.id}`, options);
    }
    return <ERPLayout><Head title={`Opening Stock ${stock.document_number}`} /><div className="min-w-0 space-y-6">
        <header className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-bold">{stock.document_number}</h1><div className="mt-2"><Status status={stock.status} /></div></div><div className="flex flex-wrap gap-2">
            <Link className={button} href={base}>Back to List</Link>
            {draft && can('erp.opening-stocks.edit') && <Link className={button} href={`${base}/${stock.id}/edit`}><Pencil size={16} />Edit</Link>}
            {draft && can('erp.opening-stocks.delete') && <button className={`${button} text-red-600`} disabled={processing} onClick={() => setDeleting(true)}><Trash2 size={16} />Delete</button>}
            {draft && can('erp.opening-stocks.post') && <button className={button} disabled={processing || stock.items.length === 0} onClick={() => setPosting(true)}><CheckCircle size={16} />Post Opening Stock</button>}
        </div></header>
        {Object.keys(errors).length > 0 && <div role="alert" className="rounded-lg bg-red-50 p-3 text-red-700">{Object.entries(errors).map(([key, message]) => <p key={key}>{message}</p>)}</div>}
        <p className="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">{draft ? 'This draft has not affected inventory. Review all lines before posting.' : 'This document has been posted to inventory and cannot be edited or deleted.'}</p>
        <section className={panel} aria-label="Document details"><dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{[
            ['Company', stock.company?.name ?? '—'], ['Warehouse', stock.warehouse ? `${stock.warehouse.code} — ${stock.warehouse.name}` : '—'], ['Document date', date(stock.document_date)],
            ['Created by', stock.creator?.name ?? '—'], ['Created at', stock.created_at ? new Date(stock.created_at).toLocaleString() : '—'], ['Posted by', stock.poster?.name ?? '—'], ['Posted at', stock.posted_at ? new Date(stock.posted_at).toLocaleString() : '—'],
        ].map(([label, value]) => <div key={label}><dt className="text-sm text-neutral-500">{label}</dt><dd className="mt-1 font-medium">{value}</dd></div>)}</dl><div className="mt-4 border-t pt-4"><h2 className="text-sm text-neutral-500">Notes</h2><p className="mt-1 whitespace-pre-wrap break-words">{stock.notes || 'No notes.'}</p></div></section>
        <section className="grid gap-3 sm:grid-cols-3" aria-label="Document totals">{[['Items', String(stock.total_items)], ['Total quantity', number(stock.total_quantity)], ['Total value', number(stock.total_value)]].map(([label, value]) => <div key={label} className={panel}><p className="text-sm text-neutral-500">{label}</p><p className="mt-1 text-2xl font-semibold">{value}</p></div>)}</section>
        <section className={panel}><h2 className="mb-4 text-lg font-semibold">Stock items</h2>{stock.items.length === 0 ? <p className="py-6 text-center text-neutral-500">No items have been added.</p> : <>
            <div className="space-y-3 md:hidden">{stock.items.map((item, i) => <article className="rounded-lg border p-3" key={item.id}><h3 className="font-medium">{i + 1}. {item.product?.name ?? 'Product unavailable'}</h3><p className="text-sm text-neutral-500">{item.product?.sku || 'No product SKU'}</p><dl className="mt-2 grid grid-cols-2 gap-2 text-sm"><dt>Variant</dt><dd>{item.variant?.name ?? (item.product_variant_id ? 'Variant unavailable' : 'No variant')}</dd><dt>Variant SKU</dt><dd>{item.variant?.sku || '—'}</dd><dt>Quantity</dt><dd>{number(item.quantity)}</dd><dt>Unit cost</dt><dd>{number(item.unit_cost)}</dd><dt>Total cost</dt><dd>{number(item.total_cost)}</dd></dl></article>)}</div>
            <div className="hidden overflow-x-auto md:block"><table className="w-full text-left text-sm"><thead><tr className="border-b">{['#', 'Product / SKU', 'Variant / SKU', 'Quantity', 'Unit cost', 'Total cost'].map(h => <th className="whitespace-nowrap p-3" key={h}>{h}</th>)}</tr></thead><tbody>{stock.items.map((item, i) => <tr key={item.id} className="border-b"><td className="p-3">{i + 1}</td><td className="p-3">{item.product?.name ?? 'Product unavailable'}<p className="text-xs text-neutral-500">{item.product?.sku || '—'}</p></td><td className="p-3">{item.variant?.name ?? (item.product_variant_id ? 'Variant unavailable' : 'No variant')}<p className="text-xs text-neutral-500">{item.variant?.sku || '—'}</p></td><td className="p-3 text-right">{number(item.quantity)}</td><td className="p-3 text-right">{number(item.unit_cost)}</td><td className="p-3 text-right">{number(item.total_cost)}</td></tr>)}</tbody><tfoot><tr className="font-semibold"><td colSpan={3} className="p-3">Totals ({stock.total_items} items)</td><td className="p-3 text-right">{number(stock.total_quantity)}</td><td /><td className="p-3 text-right">{number(stock.total_value)}</td></tr></tfoot></table></div>
        </>}</section>
        <DeleteConfirmDialog open={deleting} onOpenChange={open => { if (!processing) setDeleting(open); }} onConfirm={() => submit('delete')} title="Delete opening stock draft?" itemName={stock.document_number} processing={processing} />
        <AlertDialogContent isOpen={posting} onOpenChange={open => { if (!processing) setPosting(open); }}><AlertDialogHeader><AlertDialogTitle>Post {stock.document_number}?</AlertDialogTitle><AlertDialogDescription>This will add {number(stock.total_quantity)} units to {stock.warehouse?.name ?? 'the warehouse'} with a total value of {number(stock.total_value)}. The document will become read-only. Confirm that all products, variants, quantities, and costs are correct.</AlertDialogDescription></AlertDialogHeader><AlertDialogFooter><AlertDialogCancel isDisabled={processing}>Cancel</AlertDialogCancel><button className={button} disabled={processing} onClick={() => submit('post')}>{processing ? 'Posting…' : 'Confirm posting'}</button></AlertDialogFooter></AlertDialogContent>
    </div></ERPLayout>;
}
