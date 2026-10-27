export type Kind = 'ledger' | 'trial';
export type Company = { id: number; code: string; name: string; status?: string };
export type Filters = { from: string; to: string; account_id: string; per_page: string; q: string };
export type LedgerLine = { date: string; journal_id: number; number: string; line_number: number; debit: string; credit: string; balance: string };
export type TrialRow = { id: number; code: string; name: string; status: string; opening_debit: string; opening_credit: string; period_debit: string; period_credit: string; closing_debit: string; closing_credit: string };
export type Totals = Pick<TrialRow, 'opening_debit' | 'opening_credit' | 'period_debit' | 'period_credit' | 'closing_debit' | 'closing_credit'>;
export type Report = {
    currency: string;
    coverage_note: string;
    account?: { id: number; code: string; name: string };
    opening?: string;
    period_debit?: string;
    period_credit?: string;
    closing?: string;
    data?: LedgerLine[];
    page?: { current_page: number; last_page: number; per_page: number; total: number };
    accounts?: TrialRow[];
    totals?: Totals;
    differences?: { opening: string; period: string; closing: string };
    balanced?: boolean;
};
export type Props = {
    kind: Kind;
    companies: Company[];
    company: Company | null;
    today: string | null;
    filters: Filters;
    accounts: { id: number; label: string }[];
    accountsTruncated: boolean;
    report: Report | null;
    canBalance: boolean;
    canProfit: boolean;
    canCashFlow: boolean; canLedger: boolean;
    canTrial: boolean;
    canExport: boolean;
    errors?: Record<string, string>;
};
export const paths: Record<Kind, string> = { ledger: '/erp/accounting/general-ledger', trial: '/erp/accounting/trial-balance' };
export const titles: Record<Kind, string> = { ledger: 'General Ledger', trial: 'Trial Balance' };
export const panel = 'rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900';
export const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950';
export const button = 'inline-flex items-center justify-center rounded-lg bg-indigo-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50';
export function reportUrl(kind: Kind, company: number, filters: Filters, page = 1, format?: 'csv' | 'print'): string {
    const query = new URLSearchParams({ company_id: String(company), from: filters.from, to: filters.to, per_page: filters.per_page, page: String(page) });
    if (kind === 'ledger' && filters.account_id) query.set('account_id', filters.account_id);
    if (filters.q) query.set('q', filters.q);
    if (format) query.set('format', format);
    return `${paths[kind]}${format ? '/export' : ''}?${query.toString()}`;
}
