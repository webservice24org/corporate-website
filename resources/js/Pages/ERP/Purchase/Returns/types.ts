export const box = "mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none";
export const button = "inline-block rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm disabled:opacity-50";
export type PurchaseReturn = {
    id: number; company_id: number; goods_receipt_id: number; purchase_order_id: number; warehouse_id: number;
    number: string; status: "draft" | "approved" | "posted" | "cancelled"; reason: string; revision: number; created_by: number;
    company?: { name: string }; supplier?: { name: string }; warehouse?: { name: string };
    goods_receipt?: { number: string }; purchase_order?: { number: string; currency_code: string };
};
export type ReturnLine = { id: number; goods_receipt_item_id: number; quantity: string; unit_cost: string; source_unit_cost: string | null; is_stock_item: boolean | number | null; stock_movement_id: number | null; product_name?: string; variant_name?: string | null };
export type SourceLine = { id: number; product_name: string; variant_name: string | null; accepted: string; returned: string; remaining: string; unit_cost: string; is_stock_item: boolean };
