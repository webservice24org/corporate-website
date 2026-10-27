export type Company = { id: number; code: string; name: string };
export type Year = { id: number; code: string; name: string; state: string; revision: number; from: string; to: string };
export type Mode = 'close' | 'reopen';
export type Line = { line_number: number; account_id: number; debit: string; credit: string };
export type Labels = Record<string, { code: string; name: string }>;
export type Plan = { lines: Line[]; result: string; retained_account_id: number; no_journal_reason: string | null };
export type Review = { approvalReference: string | null; year: Year; mode: Mode; activeFrom: string; sequence: number; checks: { code: string; passed: boolean; message: string }[]; ready: boolean; fresh: boolean; latestKind: string; currency: string; plan: Plan | null; labels: Labels; events: { id: number; kind: string; actor_id: number; at: string }[]; operations: { id: number; kind: string; journal_id: number | null; result: string; date: string; lines: Line[]; labels: Labels }[]; canPrepare: boolean; canApprove: boolean; canPost: boolean; historyTruncated: boolean; operationsLimited: boolean };
export type Props = { companies: Company[]; company: Company | null; years: Year[]; review: Review | null; requestKey: string; errors?: Record<string, string> };
export const path = '/erp/accounting/year-end';
export function yearUrl(company: number, year?: number): string { const q = new URLSearchParams({ company_id: String(company) }); if (year !== undefined) q.set('year_id', String(year)); return `${path}?${q.toString()}`; }
export function actionUrl(company: number, year: number, mode: Mode, action: 'prepare' | 'approve' | 'post'): string { return `${path}/${company}/${year}/${mode}/${action}`; }
