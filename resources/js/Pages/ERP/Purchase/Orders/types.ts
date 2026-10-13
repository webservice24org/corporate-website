export type Order = {
    id: number; company_id: number; company?: { id: number; name: string } | null;
    supplier_id: number; supplier?: { id: number; name: string } | null;
    supplier_quotation_id: number | null; purchase_requisition_id: number | null;
    number: string; status: string; currency_code: string; revision: number; created_by: number; approved_by: number | null;
    expected_delivery_at: string | null; notes: string | null;
    subtotal: string; discount_total: string; tax_total: string; grand_total: string;
    submitted_at: string | null; approved_at: string | null; ordered_at: string | null;
};
export type OrderItem = { id: number; product_id: number; product_name: string | null; variant_name: string | null; quantity: string; received_quantity: string; unit_price: string; discount_amount: string; tax_amount: string; line_total: string; expected_delivery_at: string | null };
export type AwardedQuote = { id: number; quotation_number: string; currency_code: string; total_amount: string; notes: string | null; supplier?: { id: number; name: string } | null; rfq?: { id: number; number: string } | null };
export const box = "mt-1 w-full rounded-lg border p-2";
export const button = "rounded-lg border px-4 py-2 text-sm disabled:opacity-50";
