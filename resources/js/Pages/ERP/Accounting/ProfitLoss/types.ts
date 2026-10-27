export type Company = { id: number; code: string; name: string; status?: string };
export type Filters = { from: string; to: string };
export type Row = { account_id: number; code: string; name: string; status: string; debit: string; credit: string; amount: string };
export type Section = { role: string; label: string; amount: string; accounts: Row[] };
export type Issue = { account_id: number; code: string; name: string; issue: string; category?: string; statement_role?: string; period_debit?: string; period_credit?: string; amount?: string };
export type Report = {
    sections: Section[];
    classification_complete: boolean;
    issues: Issue[];
    warnings: Issue[];
    net_revenue: string;
    gross_profit: string;
    operating_result: string;
    provisional_result: string;
    period_result: string | null;
    excluded_nominal_opening_debit: string;
    excluded_nominal_opening_credit: string;
    currency: string;
    coverage_note: string;
};
export type Props = { companies: Company[]; company: Company | null; today: string | null; filters: Filters; report: Report | null; canBalance: boolean; canCashFlow: boolean; canLedger: boolean; canTrial: boolean; canAccounts: boolean; errors?: Record<string, string> };
export const path = '/erp/accounting/profit-loss';
export const panel = 'rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900';
export const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-600 dark:bg-slate-950';
export function reportUrl(company: number, filters: Filters): string {
    return `${path}?${new URLSearchParams({ company_id: String(company), from: filters.from, to: filters.to }).toString()}`;
}
export function ledgerUrl(company: number, account: number, filters: Filters): string {
    return `/erp/accounting/general-ledger?${new URLSearchParams({ company_id: String(company), account_id: String(account), from: filters.from, to: filters.to }).toString()}`;
}
