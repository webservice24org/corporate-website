export const base = '/erp/accounting/cash-bank';
export const panel = 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900';
export const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-white';
export type Filters = { q: string; kind: string; status: string; per_page: number };
export type Company = { id: number; code: string; name: string; currency_code: string; status: string };
export type Binding = {
    id: number;
    name: string;
    role: string;
    kind: string;
    currency_code: string;
    status: string;
    revision: number;
    account_id: number;
    gl: { id: number; code: string; name: string; status: string } | null;
    setup_status: string;
    issues: string[];
    usage: { receipt_documents: number; supplier_payment_documents: number; posted_journal_lines: number };
};
export type Report = {
    as_of: string | null;
    currency: string;
    status: string;
    accounting_state: string;
    issues: string[];
    profile: { id: number; version: number; effective_from: string; verified: boolean } | null;
    summary: { total_bindings: number; cash_bindings: number; bank_bindings: number; inactive_bindings: number; usable_bindings: number; review_bindings: number };
    rows: Binding[];
    missing_bindings: { role: string; account_id: number }[];
    unbound_posted_cash_lines: number;
    pagination: { current_page: number; per_page: number; total: number; last_page: number };
};
export type Props = {
    companies: Company[];
    company: Company | null;
    report: Report | null;
    canOpenSetup: boolean;
    filters: Filters;
    errors?: Record<string, string>;
};
export const issueLabels: Record<string, string> = {
    company_timezone_review: 'Review the company timezone before deriving an effective profile date.',
    company_inactive: 'This company is inactive. Historical setup remains visible.',
    accounting_setup_missing: 'Initialize the company in Accounting Setup.',
    accounting_not_enabled: 'Accounting is not enabled. This screen does not activate it.',
    cutover_locked: 'The company is locked for cutover.',
    activation_requires_review: 'The accounting activation prerequisite needs review.',
    effective_posting_profile_missing: 'No published posting profile covers the current business date.',
    effective_posting_profile_review: 'The effective profile or its mapped GL accounts could not pass setup verification.',
    cash_roles_missing: 'The effective profile has no configured cash/bank roles.',
    invalid_binding_role: 'The cash/bank role code requires review.',
    binding_kind_mismatch: 'The binding kind differs from its cash/bank role.',
    binding_currency_mismatch: 'The binding currency differs from the company currency.',
    invalid_binding_status: 'The binding has an unsupported status.',
    owned_gl_account_missing: 'The linked GL account is not available in this company.',
    gl_not_active_postable_leaf: 'The GL account must be an active postable leaf.',
    gl_cash_classification_review: 'The GL account cash-flow or normal-side classification needs review.',
    role_not_in_effective_profile: 'This historical role is absent from the effective profile.',
    mapping_transition_required: 'The current role points to a different GL account. A verified transition is required.',
    roles_without_initialized_binding: 'Some current roles do not yet have a finance-account binding.',
    binding_setup_review: 'One or more finance-account bindings require review.',
    no_usable_binding: 'No active binding passed the current setup checks.',
    unbound_posted_cash_lines: 'Posted cash GL lines without a finance-account binding need review; no automatic backfill was performed.',
};
export function label(code: string): string { return issueLabels[code] ?? code.replaceAll('_', ' '); }
export function pageUrl(companyId: number, filters: Filters, page = 1): string {
    const query = new URLSearchParams({ company_id: String(companyId), per_page: String(filters.per_page), page: String(page) });
    if (filters.q) query.set('q', filters.q);
    if (filters.kind) query.set('kind', filters.kind);
    if (filters.status) query.set('status', filters.status);
    return `${base}?${query.toString()}`;
}
