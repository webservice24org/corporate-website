export { button, input, panel, secondary } from '../CashTransfers/types';
import type { Binding, Company, Status } from '../CashTransfers/types';
export type { Binding, Company, Status } from '../CashTransfers/types';
export const base = '/erp/accounting/cash-transfer-reversals';
export type Reversal = {
    id: number; company_id: number; number: string; original_transfer_id: number; document_date: string;
    status: Status; revision: number; amount: string; currency_code: string;
    source: Binding | null; destination: Binding | null; reason?: string; cancellation_reason?: string | null;
};
export type Original = {
    id: number; number: string; document_date: string; amount: string; currency_code: string;
    source: Binding | null; destination: Binding | null; source_account_id: number; destination_account_id: number;
    url: string | null; journal_url: string | null;
};
export type Context = {
    companies: Company[]; company: Company | null; actorId: number; writable: boolean; postingEnabled: boolean;
    canCreate: boolean; today: string | null; issues: string[]; errors?: Record<string, string>;
};
export type Filters = { transfer_id: string; status: string; from: string; to: string };
export type RegisterProps = Context & {
    filters: Filters; reversals: { data: Reversal[]; current_page: number; last_page: number; total: number };
};
export type FormProps = Context & { reversal: Reversal | null; original: Original | null; requestKey: string };
export type Action = 'submit' | 'approve' | 'reject' | 'cancel' | 'post';
export type DetailProps = Context & {
    reversal: Reversal; original: Original | null; requestKey: string; capabilities: Record<Action | 'edit', boolean>;
    preview: { account_id: number; cash_account_id: number; debit: string; credit: string }[] | null;
    finance: { state: 'unposted' | 'verified' | 'review'; message: string; journal_id: number | null; journal_url: string | null };
    workflow: Record<string, number | string | null>;
    history: { id: number; action: string; actor_id: number; reason: string | null; occurred_at: string | null }[];
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
export function money(value: string): string {
    const dot = value.indexOf('.');
    const whole = dot < 0 ? value : value.slice(0, dot);
    const cents = dot < 0 ? '00' : value.slice(dot + 1);
    return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${cents}`;
}
export function keyScope(actor: number, company: number, document: string, revision?: number): string {
    return `erp:cash-transfer-reversal:${actor}:${company}:${document}${revision === undefined ? '' : `:${revision}`}`;
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
