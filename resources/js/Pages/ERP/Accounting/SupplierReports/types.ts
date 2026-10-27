export type Kind = 'statement' | 'payables' | 'reconciliation';
export const paths: Record<Kind, string> = { statement: '/erp/accounting/supplier-statements', payables: '/erp/accounting/payable-register', reconciliation: '/erp/accounting/payable-reconciliation' };
export const titles: Record<Kind, string> = { statement: 'Supplier Statements', payables: 'Payable Register', reconciliation: 'AP Reconciliation' };
export const panel = 'rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900';
export const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-white';
export const button = 'inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900';
export type Company = { id: number; code: string; name: string; currency_code: string; status: string };
export type Supplier = { id: number; code: string; name: string; status: string };
export type Filters = { supplier_id: string; from: string; to: string; as_of: string; per_page: number };
export type Issue = { code: string; supplier_id: number | null; source_kind?: string | null; source_id?: number | null; journal_line_id?: number | null; account_id?: number | null };
export type Position = { ap: string; advance: string; net: string; cash_paid: string; cash_reversed: string };
export type Page = { current_page: number; per_page: number; last_page: number; total: number };
export type Bridge = { scope: string; source: { ap: string; advance: string }; gl: { ap: string; advance: string }; difference: { ap: string; advance: string }; numeric_match: boolean; reconciled: boolean; accounts: { role: string; account_id: number; code: string | null; name: string | null; expected: string; actual: string; difference: string }[] };
export type BaseReport = { currency: string; complete: boolean; balance_status: string; exceptions: Issue[] };
export type StatementRow = { source_kind: string; source_id: number; supplier_id: number; bill_id: number | null; payment_id: number | null; date: string; event: string; number: string; amount: string; source_url: string | null; journal_url: string | null; ap_delta: string; advance_delta: string; net_delta: string; ap_balance: string; advance_balance: string; net_balance: string };
export type StatementReport = BaseReport & { from: string; to: string; supplier: Supplier; opening: Position; activity: Position; closing: Position; rows: StatementRow[]; pagination: Page; reconciliation: Bridge };
export type PayableRow = { id: number; number: string; supplier_id: number; supplier_name: string | null; native_amount: string | null; native_currency: string; invoice_date: string | null; due_date: string | null; recognized: boolean; source_url: string | null; credited_as_of: string | null; allocated_as_of: string | null; outstanding_as_of: string | null; balance_kind: string };
export type PayableReport = BaseReport & { as_of: string; due_date_basis: string; rows: PayableRow[]; totals: { ap_signed: string; open_payables: string; ap_debit_balances: string }; pagination: Page };
export type ReconciliationReport = BaseReport & { as_of: string; source_position: Position; payable_totals: { ap_signed: string; open_payables: string; ap_debit_balances: string }; reconciliation: Bridge };
export type Context = { companies: Company[]; company: Company | null; suppliers: Supplier[]; today: string | null; canExport: boolean; canReconcile: boolean; filters: Filters; kind: Kind; errors?: Record<string, string> };
export type StatementProps = Context & { report: StatementReport | null };
export type PayableProps = Context & { report: PayableReport | null };
export type ReconciliationProps = Context & { report: ReconciliationReport | null };
// Keep decimal strings intact: locale Number() conversions can lose accounting precision.
export function amount(value: string | null | undefined): string { return value ?? 'Unknown — review'; }
export function reportUrl(kind: Kind, companyId: number, filters: Filters, page?: number, format?: 'csv' | 'print'): string {
    const query = new URLSearchParams({ company_id: String(companyId) });
    if (kind === 'statement') { if (filters.supplier_id) query.set('supplier_id', filters.supplier_id); query.set('from', filters.from); query.set('to', filters.to); }
    else { query.set('as_of', filters.as_of); if (kind === 'payables' && filters.supplier_id) query.set('supplier_id', filters.supplier_id); }
    if (!format && kind !== 'reconciliation') { query.set('per_page', String(filters.per_page)); if (page) query.set('page', String(page)); }
    return `${paths[kind]}${format ? `/${format}` : ''}?${query.toString()}`;
}
