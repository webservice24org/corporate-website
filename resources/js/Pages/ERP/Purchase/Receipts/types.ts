export type Receipt = { id: number; company_id: number; company?: { id: number; name: string } | null; purchase_order_id: number; purchase_order?: { id: number; number: string; status: string; currency_code?: string } | null; warehouse_id: number; warehouse?: { id: number; name: string } | null; number: string; status: "draft" | "posted" | "cancelled"; revision: number; received_at: string; posted_at: string | null; notes: string | null };
export type ReceivableOrder = { id: number; number: string; status: string; supplier?: { id: number; name: string } | null };
export type OrderLine = { id: number; product_id: number; product_name: string | null; variant_name: string | null; quantity: string; received_quantity: string; remaining: string };
export type ReceiptLine = { id: number; purchase_order_item_id: number; quantity_received: string | number; quantity_rejected: string | number; unit_cost: string | number; product_name?: string | null; variant_name?: string | null; is_stock_item: boolean | number; stock_movement_id: number | null };
export const box = "mt-1 w-full rounded-lg border p-2";
export const button = "rounded-lg border px-4 py-2 text-sm disabled:opacity-50";
