export type Company = { id: number; code: string; name: string; status?: string };
export type Filters = { from: string; to: string };
export type Flows = { operating: string; investing: string; financing: string; net_change: string };
export type Issue = { issue: string; account_id?: number; journal_id?: number; binding_id?: number; code?: string; name?: string; amount?: string; category?: string; statement_role?: string; period_debit?: string; period_credit?: string; debit?: string; credit?: string };
export type NoncashJournal = { journal_id: number; date: string; number: string; profit_movement: string; operating_balance_adjustment: string; investing_balance_change: string; financing_balance_change: string };
export type CashJournal = { journal_id: number; date: string; number: string; cash_movement: string; operating: string; investing: string; financing: string };
export type Report = {
    complete: boolean;
    confirmed_flows: Flows | null;
    provisional_flows: Flows;
    issues: Issue[];
    warnings: Issue[];
    beginning_cash: string;
    closing_cash: string;
    opening_cash_movements: string;
    economic_cash_movement: string;
    unallocated_cash_counterparts: string;
    reconciliation_difference: string;
    snapshot_difference: string;
    indirect_operating_difference: string;
    indirect_bridge: { period_result: string; operating_balance_adjustments: string; other_noncash_adjustments: string; earnings_reclassification: string; operating_cash: string };
    cash_accounts: { account_id: number; code: string; name: string; status: string; beginning: string; closing: string }[];
    noncash_journals: NoncashJournal[];
    cash_journals: CashJournal[];
    cash_transfer_count: number;
    currency: string;
    coverage_note: string;
};
export type Props = { companies: Company[]; company: Company | null; today: string | null; filters: Filters; report: Report | null; canExport: boolean; canLedger: boolean; canTrial: boolean; canProfit: boolean; canBalance: boolean; canAccounts: boolean; errors?: Record<string, string> };
export const path = '/erp/accounting/cash-flow';
export const panel = 'rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900';
export const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-600 dark:bg-slate-950';
export function reportUrl(company: number, filters: Filters, format?: 'csv' | 'print'): string {
    const query = new URLSearchParams({ company_id: String(company), from: filters.from, to: filters.to });
    if (format) query.set('format', format);
    return `${path}${format ? '/export' : ''}?${query.toString()}`;
}
export function ledgerUrl(company: number, account: number, filters: Filters): string {
    return `/erp/accounting/general-ledger?${new URLSearchParams({ company_id: String(company), account_id: String(account), from: filters.from, to: filters.to }).toString()}`;
}

export function pageRows<T>(rows: T[], page: number): { current: number; last: number; rows: T[] } {
    const last = Math.max(1, Math.ceil(rows.length / 25));
    const current = Math.min(Math.max(1, page), last);
    return { current, last, rows: rows.slice((current - 1) * 25, current * 25) };
}
