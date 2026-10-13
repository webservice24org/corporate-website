export const box = "mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none";
export const button = "inline-block rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm disabled:opacity-50";
export type Bill = {
    id: number; company_id: number; supplier_id: number; goods_receipt_id: number | null; purchase_order_id: number | null;
    number: string; supplier_invoice_number: string; status: "draft" | "approved" | "cancelled"; revision: number; created_by: number;
    invoice_date: string; due_date: string | null; notes: string | null; currency_code: string; subtotal: string; discount_total: string; tax_total: string; grand_total: string;
    company?: { name: string }; supplier?: { name: string }; goods_receipt?: { number: string } | null; purchase_order?: { number: string } | null;
};
export type BillLine = { id: number; goods_receipt_item_id: number | null; quantity: string; description: string; unit_price: string; discount_amount: string; tax_amount: string; line_total: string; credit_review_quantity?: string | null };
export type SourceLine = { id: number; product_name: string; variant_name: string | null; accepted: string; returned: string; billed: string; remaining: string; unit_price: string; ordered_quantity: string; order_discount: string; order_tax: string };
export type Payable = { id: number; supplier_bill_id: number; company_name: string; supplier_name: string; bill_number: string; supplier_invoice_number: string; currency_code: string; amount: string; paid_amount: string; outstanding: string; due_date: string | null; overdue: boolean };
export type Page<T> = { data: T[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
