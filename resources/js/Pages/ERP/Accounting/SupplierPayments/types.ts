export const base = '/erp/accounting/supplier-payments';
export const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 disabled:bg-slate-100';
export const button = 'inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50';
export const panel = 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm';
export type Company = { id: number; name: string; code: string; currency_code: string; status: string };
export type Context = { companies: Company[]; company: Company | null; canCreate: boolean; writable: boolean; postingEnabled: boolean; today: string | null; issues: string[] };
export type Supplier = { id: number; code: string; name: string; status: string };
export type CashAccount = { id: number; name: string; role: string; kind: 'cash' | 'bank'; selectable: boolean };
export type Bill = { id: number; number: string; amount: string; paid: string; credited: string; outstanding: string; due_date: string | null };
export type Target = { supplier_bill_id: number; amount: string };
export type Payment = { kind: 'payment' | 'reversal'; reverses_payment_id: number | null; id: number; company_id: number; supplier_id: number; cash_account_id: number; number: string; document_date: string; currency_code: string; status: string; revision: number; payment_method: string; amount: string; advance_amount: string; payment_reference?: string | null; notes?: string | null; reason?: string | null };
export type Options = { suppliers: Supplier[]; cashAccounts: CashAccount[]; bills: Bill[]; selectedSupplierId: number | null };
/** Exact preview using digit strings; no floating point conversion of money. */
export function cents(value: string): bigint | null {
    if (!/^\d{1,18}(\.\d{1,2})?$/.test(value)) return null;
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * BigInt(100) + BigInt(fraction.padEnd(2, '0'));
}
export function money(value: bigint): string {
    const negative = value < BigInt(0); const digits = (negative ? -value : value).toString().padStart(3, '0');
    return `${negative ? '-' : ''}${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

export type Allocation = { id: number; supplier_bill_id: number; amount: string; kind: 'allocation' | 'compensation'; original_allocation_id: number | null; is_active: boolean; posted_at: string | null };
export type Finance = { id: number; source_kind: string; source_id: number; event: string; effect: string; outcome: string; date: string; journal_url: string | null };
export type ReversalReview = { id: number; company_id: number; supplier_payment_id: number; revision: number; status: string; document_date: string; reason: string; cancellation_reason: string | null; reversal_payment_id: number | null; created_by: number; last_edited_by: number; submitted_by: number | null; approved_by: number | null; posted_by: number | null; cancelled_by: number | null; submitted_at: string | null; approved_at: string | null; posted_at: string | null; cancelled_at: string | null };
export type Position = { revision: number; history_hash: string; allocated: string; available: string; cash_reversed: boolean; history_count: number };
export type PaymentAction = 'submit' | 'approve' | 'reject' | 'cancel' | 'post';
export type Detail = Context & { payment: Payment; supplier: Supplier | null; cashAccount: { name: string; role: string; status: string } | null; targets: Target[]; allocations: Allocation[]; billNames: Record<string, string>; workflow: Record<string, number | string | null>; history: { id: number; action: string; actor_id: number; reason: string | null; created_at: string | null }[]; capabilities: Record<'edit' | PaymentAction, boolean>; postingKey: string | null; canViewBills: boolean; actionCapabilities: { allocate: boolean; prepareReversal: boolean }; financeIssues: string[]; financeVerified: boolean; position: Position | null; originalPayment: Payment | null; linkedReversal: Payment | null; pendingReversalId: number | null; reversalRequests: ReversalReview[]; finance: Finance[] };
export type ReversalDetail = Detail & { reversalRequest: ReversalReview; reversalCapabilities: Record<'edit' | PaymentAction, boolean>; reversalDateCurrent: boolean; reversalPostingKey: string | null };
export const reviewUrl = (id: number) => `${base}/reversal-requests/${id}`;
