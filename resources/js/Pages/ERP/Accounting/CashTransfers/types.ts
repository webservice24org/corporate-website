export const base = '/erp/accounting/cash-transfers';
export const panel = 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900';
export const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-white';
export const button = 'inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-900';
export const secondary = 'inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium dark:border-slate-600';
export type Status = 'draft' | 'submitted' | 'approved' | 'posted' | 'cancelled';
export type Company = { id: number; code: string; name: string; currency_code: string; status: string };
export type Binding = { id: number; name: string; role: string; kind: string; currency_code: string; status: string; selectable?: boolean };
export type Transfer = {
    id: number; company_id: number; number: string; document_date: string; status: Status; revision: number;
    amount: string; currency_code: string; source_cash_account_id: number; destination_cash_account_id: number;
    source: Binding | null; destination: Binding | null; purpose?: string; notes?: string | null; cancellation_reason?: string | null;
};
export type Context = {
    companies: Company[]; company: Company | null; actorId: number; writable: boolean; postingEnabled: boolean;
    canCreate: boolean; today: string | null; issues: string[]; errors?: Record<string, string>;
};
export type Filters = { q: string; status: string; binding_id: string; from: string; to: string };
export type RegisterProps = Context & {
    bindings: Binding[]; filters: Filters;
    transfers: { data: Transfer[]; current_page: number; last_page: number; total: number };
};
export type FormProps = Context & { bindings: Binding[]; transfer: Transfer | null; requestKey: string };
export type Action = 'submit' | 'approve' | 'reject' | 'cancel' | 'post';
export type DetailProps = Context & {
    transfer: Transfer; requestKey: string; capabilities: Record<Action | 'edit', boolean>;
    finance: { state: 'unposted' | 'verified' | 'review'; message: string; journal_id: number | null; journal_url: string | null };
    workflow: Record<string, number | string | null>;
    history: { id: number; action: string; actor_id: number; reason: string | null; occurred_at: string | null }[];
    reversalLinks: { create_url: string | null; register_url: string | null; requests: { id: number; status: Status; url: string }[] };
    historyTruncated: boolean; bookLinks: { label: string; url: string }[];
};
export function transferUrl(companyId?: number | null, filters?: Filters, page?: number): string {
    const query = new URLSearchParams();
    if (companyId) query.set('company_id', String(companyId));
    if (filters) Object.entries(filters).forEach(([name, value]) => { if (value) query.set(name, value); });
    if (page && page > 1) query.set('page', String(page));
    const suffix = query.toString();
    return `${base}${suffix ? `?${suffix}` : ''}`;
}
// Accounting amounts remain exact strings, including values beyond JavaScript's safe integer range.
export function money(value: string): string {
    const dot = value.indexOf('.');
    const whole = dot < 0 ? value : value.slice(0, dot);
    const cents = dot < 0 ? '00' : value.slice(dot + 1);
    return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${cents}`;
}
export function keyScope(actor: number, company: number, document: string, revision?: number): string {
    return `erp:cash-transfer:${actor}:${company}:${document}${revision === undefined ? '' : `:${revision}`}`;
}
// Keep only the opaque request key in this tab; no amount, purpose, notes or evidence is stored.
export function stableKey(scope: string, fallback: string): string {
    if (typeof window === 'undefined') return fallback;
    try {
        const existing = window.sessionStorage.getItem(scope);
        if (existing && /^[A-Za-z0-9_-]{16,64}$/.test(existing)) return existing;
        window.sessionStorage.setItem(scope, fallback);
    } catch { /* The mounted form still retains its key when browser storage is unavailable. */ }
    return fallback;
}
export function clearKey(scope: string, key: string): void {
    if (typeof window === 'undefined') return;
    try { if (window.sessionStorage.getItem(scope) === key) window.sessionStorage.removeItem(scope); }
    catch { /* Storage is optional; server idempotency remains authoritative. */ }
}
