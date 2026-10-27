import type { Company } from '../CashTransfers/types';
export type Module = 'adjustments' | 'reconciliations';
export type Context = {
    module: Module; actorId: number; companies: Company[]; company: Company | null;
    banks: { id: number; name: string; currency_code: string; status: string }[];
    writable: boolean; canCreate: boolean; canAdjust: boolean; canReconcile: boolean; today: string | null;
    errors?: Record<string, string>;
};
export type RecordRow = {
    id: number; cash_account_id: number; revision: number; status: string; currency_code?: string;
    statement_reference?: string; starts_on?: string; ends_on?: string; amount?: string; kind?: string;
    document_date?: string; statement_line_id?: number; counter_account_id?: number; reverses_id?: number | null;
    reason?: string; cancellation_reason?: string | null; created_by?: number; submitted_by?: number | null; approved_by?: number | null;
    statement_id?: number;
};
export type RegisterProps = Context & {
    records: { data: RecordRow[]; current_page: number; last_page: number; total: number };
    filters: { cash_account_id: string; status: string };
};
export type FormProps = Context & {
    mode: 'create' | 'edit' | 'refund'; record: RecordRow | null; original?: RecordRow | null;
    selection?: { cash_account_id: string; statement_id: string };
    statements?: { id: number; label: string }[];
    sources?: { reference: string; description: string; id: number; date: string; ends_on: string; direction: string; amount: string; selectable: boolean }[];
    counters?: { id: number; code: string; name: string; kind: string }[];
    issues: string[]; existingUrl?: string | null; requestKey: string;
};
export type AdjustmentProps = Context & {
    record: RecordRow; requestKey: string; capabilities: Record<string, boolean>; financeState: string;
    issues: string[]; preview: { account_id: number; cash_account_id: number | null; debit: string; credit: string }[];
    journalUrl: string | null; statementUrl: string | null; refundUrl: string | null; refunds: RecordRow[];
    history: { id: number; action: string; actor_id: number; reason: string | null; occurred_at: string }[]; historyTruncated: boolean;
};
export type MatchRow = { reference?: string; description?: string; source_url?: string | null; journal_url?: string | null; id: number; date: string; direction: string; amount: string; remaining?: string; matched?: string;
    matched_through_cutoff?: string; outstanding_through_cutoff?: string; currently_available?: string; allocated_to_later_statements?: string };
export type MatchChoice = { id: number; direction: string; available: string; amount: string };
export type Page = { current: number; last: number; total: number; parameter: string };
export type ReconciliationProps = Context & {
    record: RecordRow; requestKey: string; issues: string[]; exportLinks?: Record<string, string>; finalization?: { state: string; frozen: boolean }; statementUrl: string; bookUrl: string;
    capabilities: { match: boolean; unmatch: boolean }; filters: { direction: string; outstanding_only: boolean };
    pages: Record<'statement_rows' | 'book_rows' | 'history', Page>;
    report: null | { currency_code: string; revision: number; status: string; finalized: boolean; message: string;
        bridge: Record<string, string>; statement_rows: MatchRow[]; book_rows: MatchRow[];
        history: { id: number; kind: string; active: boolean; total: string; direction: string; actor_id: number;
            reason: string; occurred_at: string; target_match_id: number | null; legs: { side: string; id: number; amount: string }[] }[] };
};
export const base = (module: Module) => `/erp/accounting/bank-${module}`;
export const title = (module: Module) => module === 'adjustments' ? 'Bank adjustments' : 'Bank reconciliation';
