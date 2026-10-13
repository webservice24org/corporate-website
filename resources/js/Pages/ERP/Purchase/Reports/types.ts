export const box = "mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none";
export const button = "inline-block rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm disabled:opacity-50";
export type ReportType = "orders" | "receipts" | "returns" | "bills" | "payables";
export type Filters = { company_id?: number | string; supplier_id?: number | string; currency?: string; from_date: string; to_date: string; type: ReportType };
export type Options = { companies: { id: number; name: string }[]; suppliers: { id: number; company_id: number; name: string }[]; currencies: string[] };
export type Money = { company_id: number; company_name: string; currency_code: string; order_value: string; receipt_stock_value: string; return_stock_value: string; bill_value: string; outstanding: string; overdue: string };
export type SupplierSummary = { company_id: number; supplier_id: number; company_name: string; supplier_name: string; currency_code: string; bill_count: number; bill_value: string; outstanding: string };
export type Month = { month: string; company_name: string; currency_code: string; bill_count: number; bill_value: string };
export type ReportRow = { id: number; document_id: number; number: string; integrity: string; [key: string]: string | number | boolean | null };
export const reportLabels: Record<ReportType, string> = { orders: "Released orders", receipts: "Posted receipt lines", returns: "Posted return lines", bills: "Approved bills", payables: "Current unpaid invoices" };
export const dateLabels: Record<ReportType, string> = { orders: "Order release date", receipts: "GRN received date", returns: "Return posting date", bills: "Bill approval date", payables: "Current balances; period dates do not apply" };
export function queryString(filters: Filters, type?: ReportType): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries({ ...filters, type: type ?? filters.type })) {
        if (value !== undefined && value !== null && value !== "") params.set(key, String(value));
    }
    return params.toString();
}
