import type { Company } from '../types';
export type Group = { id: number; name: string; category: string; normal_side: string };
export type Account = { id: number; account_group_id: number; parent_id: number | null; code: string; name: string; normal_side: string; is_postable: boolean; control_role: string | null; statement_role: string; cash_flow_role: string | null; status: string; revision: number; depth: number; semantic_locked: boolean; has_children: boolean; can_edit: boolean; can_change_status: boolean; status_blockers: string[]; is_context?: boolean };
export type Context = { companies: Company[]; company: Company | null; groups: Group[]; issues: string[]; ready: boolean; canCreate: boolean; canInitializeGroups: boolean; canViewSetup: boolean; statementRoles: Record<string, string[]>; controlRoles: Record<string, string> };
export const base = '/erp/accounting/accounts';
export const input = 'w-full rounded border border-slate-300 p-2 disabled:bg-slate-100';
