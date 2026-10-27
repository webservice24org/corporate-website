export type Company = { id: number; code: string; name: string; status?: string };
export type FiscalYear = { id: number; code: string; starts_on: string; ends_on: string; state: string };
export type Row = { account_id: number; code: string; name: string; status: string; debit: string; credit: string; amount: string };
export type Section = { role: string; label: string; amount: string; accounts: Row[] };
export type Issue = { account_id?: number; code?: string; name?: string; issue: string; category?: string; statement_role?: string; debit?: string; credit?: string; amount?: string };
export type Totals = { assets: string; liabilities: string; posted_equity: string; equity_with_earnings: string; liabilities_and_equity: string };
export type Report = {
    sections: Section[];
    totals: Totals;
    confirmed_totals: Totals | null;
    complete: boolean;
    equation_balanced: boolean;
    difference: string;
    issues: Issue[];
    warnings: Issue[];
    earnings: { residual_total: string; prior_year_unclosed: string | null; current_year_nominal_opening: string | null; current_year_unclosed_result: string | null; bridge_difference: string };
    currency: string;
    coverage_note: string;
    fiscal_year_matches: number;
};
export type Props = { companies: Company[]; company: Company | null; today: string | null; asOf: string; fiscalYear: FiscalYear | null; report: Report | null; canCashFlow: boolean; canLedger: boolean; canTrial: boolean; canProfit: boolean; canAccounts: boolean; errors?: Record<string, string> };
export const path = '/erp/accounting/balance-sheet';
export const panel = 'rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900';
export const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-600 dark:bg-slate-950';
export function reportUrl(company: number, asOf: string): string {
    return `${path}?${new URLSearchParams({ company_id: String(company), as_of: asOf }).toString()}`;
}
export function ledgerUrl(company: number, account: number, asOf: string): string {
    return `/erp/accounting/general-ledger?${new URLSearchParams({ company_id: String(company), account_id: String(account), from: '1900-01-01', to: asOf }).toString()}`;
}
