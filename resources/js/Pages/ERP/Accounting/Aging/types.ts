export type Kind = 'ar' | 'ap';
export type Company = { id: number; code: string; name: string; status?: string };
export type Bucket = 'current' | 'days_1_30' | 'days_31_60' | 'days_61_90' | 'days_91_120' | 'over_120' | 'unknown_due';
export type AgingRow = { document_id: number; party_id: number; party_label: string; number: string; recognized_on: string | null; due_date: string | null; due_basis: string; days_overdue: number | null; bucket: Bucket | null; outstanding: string | null; balance_kind: string; native_url: string | null };
export type Issue = { issue: string; document_id?: number; document_number?: string; source_kind?: string; source_id?: number; account_id?: number; journal_id?: number; supplier_id?: number | null; role?: string; amount?: string; code?: string };
export type Totals = { open_documents: string; document_credits: string; signed_documents: string; unknown_documents: number; buckets: Record<Bucket, string> };
export type Report = { as_of: string; rows: AgingRow[]; totals: Totals; confirmed_totals: Totals | null; complete: boolean; issues: Issue[]; currency: string; coverage_basis: string; due_date_policy: string; legacy_opening_coverage_integrated: boolean; reconciliation: { source: Record<string, string>; gl: Record<string, string>; difference: Record<string, string>; document_difference: string; separate_unapplied_or_advance_balance: string; reconciled: boolean } };
export type Props = { kind: Kind; companies: Company[]; company: Company | null; today: string | null; asOf: string; report: Report | null; canAr: boolean; canAp: boolean; canExport: boolean; canLedger: boolean; canTrial: boolean; errors?: Record<string, string> };
export const paths: Record<Kind, string> = { ar: '/erp/accounting/ar-aging', ap: '/erp/accounting/ap-aging' };
export const titles: Record<Kind, string> = { ar: 'AR Aging', ap: 'AP Aging' };
export const bucketLabels: Record<Bucket, string> = { current: 'Current / due today', days_1_30: '1–30 days', days_31_60: '31–60 days', days_61_90: '61–90 days', days_91_120: '91–120 days', over_120: 'Over 120 days', unknown_due: 'Unknown due date' };
export const panel = 'rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900';
export const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-600 dark:bg-slate-950';
export function reportUrl(kind: Kind, company: number, asOf: string, format?: 'csv' | 'print'): string {
    const query = new URLSearchParams({ company_id: String(company), as_of: asOf }); if (format) query.set('format', format);
    return `${paths[kind]}${format ? '/export' : ''}?${query.toString()}`;
}
export function ledgerUrl(company: number, account: number, asOf: string): string {
    return `/erp/accounting/general-ledger?${new URLSearchParams({ company_id: String(company), account_id: String(account), from: '1900-01-01', to: asOf }).toString()}`;
}
export function pageRows<T>(rows: T[], page: number): { current: number; last: number; rows: T[] } {
    const last = Math.max(1, Math.ceil(rows.length / 25)); const current = Math.min(Math.max(1, page), last);
    return { current, last, rows: rows.slice((current - 1) * 25, current * 25) };
}
export function amountLabel(value: string | null): string { return value === null ? 'Unknown' : value; }
