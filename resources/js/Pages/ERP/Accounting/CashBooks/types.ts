export type Kind = 'cash' | 'bank';
export const paths: Record<Kind, string> = { cash: '/erp/accounting/cashbook', bank: '/erp/accounting/bankbook' };
export const titles: Record<Kind, string> = { cash: 'Cashbook', bank: 'Bankbook' };
export const panel = 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900';
export const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-white';
export type Company = { id: number; code: string; name: string; currency_code: string; status: string };
export type Binding = { id: number; name: string; role: string; kind: Kind; currency_code: string; status: string; account_id: number };
export type Filters = { binding_id: string; from: string; to: string; per_page: number };
export type Totals = { opening: string; debits: string; credits: string; closing: string };
export type Issue = { code: string; journal_id: number | null; journal_line_id: number | null };
export type Row = {
    journal_id: number;
    journal_line_id: number;
    recorded_binding_id: number | null;
    date: string;
    number: string | null;
    source_kind: string | null;
    source_id: number | null;
    event: string | null;
    source_url: string | null;
    journal_url: string | null;
    debit: string;
    credit: string;
    movement: string;
    posted_balance: string;
    verified_balance: string;
    verified: boolean;
};
export type Report = {
    binding: Binding;
    from: string;
    to: string;
    posted_gl: Totals;
    source_verified: Totals;
    difference: Totals;
    complete: boolean;
    balance_status: string;
    exceptions: Issue[];
    excluded_posted_lines: number;
    posted_totals_complete: boolean;
    rows: Row[];
    pagination: { current_page: number; per_page: number; total: number; last_page: number };
};
export type Props = {
    companies: Company[];
    company: Company | null;
    bindings: Binding[];
    kind: Kind;
    today: string | null;
    canExport: boolean;
    canReadiness: boolean;
    filters: Filters;
    report: Report | null;
    errors?: Record<string, string>;
};
export function bookUrl(kind: Kind, companyId: number, filters: Filters, page?: number, format?: 'csv' | 'print'): string {
    const query = new URLSearchParams({ company_id: String(companyId) });
    if (filters.binding_id) query.set('binding_id', filters.binding_id);
    if (filters.from) query.set('from', filters.from);
    if (filters.to) query.set('to', filters.to);
    if (!format) {
        query.set('per_page', String(filters.per_page));
        if (page) query.set('page', String(page));
    }
    return `${paths[kind]}${format ? `/${format}` : ''}?${query.toString()}`;
}
// Format the string directly; never convert an accounting amount to a JavaScript number.
export function money(value: string): string {
    return value.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
export const issueLabels: Record<string, string> = {
    native_cash_transfer_evidence_review: 'The original transfer or its reversal family needs evidence review.',
    native_bank_adjustment_evidence_review: 'Native bank adjustment operation or its cash journal needs review.',
    native_cash_transfer_reversal_evidence_review: 'The reversal operation or a journal leg is missing or inconsistent.',
    binding_classification_review: 'The binding or GL classification needs review.',
    cash_source_integrity_or_adapter_review: 'Source evidence failed verification, or its verification adapter is not installed.',
    inconsistent_posting_state: 'A non-posted journal carries posting markers; it was not counted as posted.',
    incomplete_posting_markers: 'A posted journal is missing its posting timestamp or actor.',
    foreign_currency_cash_line: 'A foreign-currency cash line was excluded; no FX amount was inferred.',
    binding_gl_identity_mismatch: 'A binding points to an inconsistent GL line; the line was excluded.',
    invalid_cash_amount: 'An invalid cash amount was excluded from the numeric projection.',
    unbound_or_wrong_binding_cash_line: 'A GL cash line has no matching finance binding.',
    cash_source_line_mismatch: 'The cash line differs from its verified native source.',
    native_cash_line_missing_or_unbound: 'A final native receipt/payment has no matching bound cash line.',
    native_cash_journal_missing_or_unposted: 'A final native receipt/payment has no complete posted cash journal.',
};
export function issueLabel(code: string): string { return issueLabels[code] ?? code.replaceAll('_', ' '); }
