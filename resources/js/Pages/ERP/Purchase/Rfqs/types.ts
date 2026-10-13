export type Company = { id: number; name: string };
export type Rfq = { id: number; company_id: number; company?: Company | null; purchase_requisition_id: number; number: string; status: "draft" | "issued" | "awarded"; currency_code: string; revision: number; deadline_at: string | null; notes: string | null; awarded_quotation_id: number | null };
export type Line = { id: number; product_name: string; variant_name: string | null; quantity: string | number; estimated_unit_price: string | number; notes: string | null };
export type Supplier = { id: number; name: string; code?: string; currency_code?: string };
export type QuoteLine = { rfq_item_id: number; unit_price: string; discount_amount: string; tax_amount: string; line_total: string | number };
export type Quote = { id: number; supplier_id: number; supplier?: Supplier | null; quotation_number: string; status: string; currency_code: string; total_amount: string; revision: number; quoted_at: string | null; valid_until: string | null; notes: string | null; expired?: boolean; eligible?: boolean; items?: QuoteLine[] };
export const box = "mt-1 w-full rounded-lg border p-2";
export const button = "rounded-lg border px-4 py-2 text-sm disabled:opacity-50";
