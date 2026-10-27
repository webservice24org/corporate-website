export { button, input, money, panel, secondary, stableKey, clearKey } from '../CashTransfers/types';
import type { Company } from '../CashTransfers/types';
export const base = '/erp/accounting/bank-statements';
export type Bank = { id: number; name: string; role: string; status: string; currency_code: string };
export type Context = { companies: Company[]; company: Company | null; banks: Bank[]; actorId: number; canImport: boolean; canCreate: boolean; canExport: boolean; today: string | null; errors?: Record<string, string> };
export type Metadata = { company_id: string; cash_account_id: string; statement_reference: string; currency_code: string; starts_on: string; ends_on: string; opening_balance: string; closing_balance: string };
export type Entry = { date: string; value_date: string; transaction_id: string; reference: string; description: string; money_in: string; money_out: string; balance: string };
export type Statement = { id: number; company_id: number; cash_account_id: number; bank_name: string | null; statement_reference: string; currency_code: string; source_mode: string; starts_on: string; ends_on: string; opening_balance: string; closing_balance: string; money_in: string; money_out: string; row_count: number; created_by?: number; imported_at?: string };
export type Row = Omit<Entry, 'value_date' | 'balance'> & { value_date: string | null; balance: string | null; line_number: number; running_balance: string };
export type Filters = { cash_account_id: string; from: string; to: string };
export type RegisterProps = Context & { filters: Filters; statements: { data: Statement[]; current_page: number; last_page: number; total: number } };
export type FormProps = Context & { requestKey: string };
export type Preview = { existing_url: string | null; preview_hash: string; metadata: Omit<Metadata, 'company_id' | 'cash_account_id'> & { company_id: number; cash_account_id: number }; lines: Row[]; totals: { money_in: string; money_out: string; row_count: number }; warnings: string[] };
export type DetailProps = Context & { workflowLinks?: { label: string; url: string }[]; statement: Statement; evidenceState: 'verified' | 'review'; rows: Row[]; page: { current: number; last: number; total: number }; exportUrl: string | null; bookUrl: string | null };
export function registerUrl(companyId?: number | null, filters?: Filters, page?: number): string {
    const q = new URLSearchParams();
    if (companyId) q.set('company_id', String(companyId));
    if (filters) Object.entries(filters).forEach(([field, value]) => { if (value) q.set(field, value); });
    if (page && page > 1) q.set('page', String(page));
    const suffix = q.toString();
    return `${base}${suffix ? `?${suffix}` : ''}`;
}
export function emptyEntry(date: string): Entry {
    return { date, value_date: '', transaction_id: '', reference: '', description: '', money_in: '', money_out: '', balance: '' };
}
