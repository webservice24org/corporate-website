export const base = '/erp/accounting/journals';
export const button = 'rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50';
export const input = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900';
export const panel = 'rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900';
export type Company = { id: number; name: string; code: string; currency_code: string; timezone: string; status: string };
export type Context = { companies: Company[]; company: Company | null; issues: string[]; canCreate: boolean };
export type Option = { id: number; label: string; selectable: boolean };
export type Options = { accounts: Option[]; branches: Option[]; departments: (Option & { branch_id: number | null })[] };
export type Journal = { id: number; company_id: number; number: string; entry_kind: string; status: string; revision: number; accounting_date: string; document_date: string | null; currency_code: string; description: string; reason: string | null; created_by: number; updated_by: number; submitted_by: number | null; submitted_at: string | null; approved_by: number | null; approved_at: string | null; posted_by: number | null; posted_at: string | null; reverses_journal_id: number | null; payload_hash: string };
export type Line = { line_number: number; account_id: number; debit: string; credit: string; branch_id: number | null; department_id: number | null; description: string | null };
export type Linked = { id: number; number: string; status: string; revision: number };
export type Action = 'submit' | 'approve' | 'reject' | 'cancel' | 'post';
export type Detail = Context & Options & { journal: Journal; lines: Line[]; total_debit: string; total_credit: string; capabilities: Record<Action | 'edit' | 'reverse', boolean>; postingIssues: string[]; linkedReversal: Linked | null; canViewAudit: boolean; audit: { id: number; action: string; actor: string; reason: string; occurred_at: string | null }[] };

/** Exact cents for preview only; the server remains authoritative. */
export function cents(value: string): bigint | null {
    if (!/^\d{1,18}(?:\.\d{1,2})?$/.test(value)) return null;
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}
export function money(value: bigint): string { return `${value / 100n}.${(value % 100n).toString().padStart(2, '0')}`; }
export function companyUrl(id: number): string { return `${base}?company_id=${id}`; }
