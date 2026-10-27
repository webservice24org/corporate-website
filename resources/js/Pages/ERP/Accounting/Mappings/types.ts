import type { Company } from '../types';
export type Version = { id: number; version: number; state: string; effective_from: string; payload_hash: string | null; mappings: Record<string, number> };
export type Account = { id: number; code: string; name: string; status: string; is_postable: boolean; category: string | null; control_role: string | null; statement_role: string; cash_flow_role: string | null; normal_side: string };
export type Props = { companies: Company[]; company: Company | null; issues: string[]; roles: Record<string, string>; controls: Record<string, string | null>; statements: Record<string, string[]>; versions: Version[]; suggestedMappings: Record<string, number>; accounts: Account[]; canPublish: boolean; canViewAccounts: boolean; canViewSetup: boolean };
export const base = '/erp/accounting/mappings';
export const input = 'w-full rounded border border-slate-300 p-2 disabled:bg-slate-100';
export function label(role: string): string { return role.replace(/_/g, ' '); }
