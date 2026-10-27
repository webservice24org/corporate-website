export type Company = { id: number; code: string; name: string };
export type Period = { id: number; year_id: number; code: string; state: string; revision: number; from: string; to: string };
export type Review = { approvedReview: { actorId: number; at: string; inventoryAmount: string; dispatchedAmount: string; inventoryReference: string; adjustmentsReference: string; current: boolean } | null; period: Period; sequence: number; checks: { code: string; passed: boolean; message: string; count: number | null }[]; inventoryGl: { inventory: string | null; dispatched_inventory: string | null }; currency: string; automatedReady: boolean; latestKind: string; fresh: boolean; events: { id: number; kind: string; actor_id: number; at: string }[]; historyTruncated: boolean; canPrepare: boolean; canApprove: boolean; canClose: boolean; calendarUrl: string | null };
export type Props = { companies: Company[]; company: Company | null; periods: Period[]; review: Review | null; errors?: Record<string, string> };
export const path = '/erp/accounting/period-close';
export function reviewUrl(company: number, period?: number): string {
    const q = new URLSearchParams({ company_id: String(company) });
    if (period !== undefined) q.set('period_id', String(period));
    return `${path}?${q.toString()}`;
}
export function actionUrl(company: number, period: number, action: 'prepare' | 'approve' | 'hard-close'): string {
    return `${path}/${company}/${period}/${action}`;
}
