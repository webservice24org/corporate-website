import type { Company } from '../types';
export type Year = { id: number; code: string; name: string; starts_on: string; ends_on: string; state: string; revision: number; semantic_locked: boolean };
export type Period = Omit<Year, 'name'> & { last_closed_at: string | null; can_close: boolean; can_reopen: boolean; reopen_requires_independent: boolean };
export type Context = { companies: Company[]; company: Company | null; issues: string[]; canViewSetup: boolean };
export const base = '/erp/accounting/calendar';
export const input = 'w-full rounded border border-slate-300 p-2 disabled:bg-slate-100';
