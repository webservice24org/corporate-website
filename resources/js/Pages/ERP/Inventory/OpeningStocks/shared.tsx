export type Company = { id: number; name: string };
export type Warehouse = { id: number; company_id: number; code: string; name: string; is_active?: boolean };
export type StockItem = {
    id: number; product_id: number; product_variant_id: number | null;
    quantity: string | number; unit_cost: string | number; total_cost: string | number;
    product: { id: number; name: string; sku: string | null } | null;
    variant: { id: number; name: string; sku: string | null } | null;
};
export type OpeningStock = {
    id: number; company_id: number; warehouse_id: number; document_number: string;
    document_date: string; status: string; notes: string | null;
    total_items: number; total_quantity: string | number; total_value: string | number;
    company: Company | null; warehouse: Warehouse | null;
    creator: { id: number; name: string } | null;
    poster: { id: number; name: string } | null;
    created_at: string | null; posted_at: string | null;
};
export const base = '/erp/inventory/opening-stocks';
export const button = 'inline-flex items-center justify-center gap-2 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900';
export const panel = 'rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900';
export const number = (value: string | number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(Number(value));
export const date = (value: string | null) => value ? value.slice(0, 10) : '—';
export function Status({ status }: { status: string }) {
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${status === 'posted' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'}`}>{status.charAt(0).toUpperCase() + status.slice(1)}</span>;
}
