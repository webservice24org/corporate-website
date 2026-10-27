export type Company = { id: number; code: string; name: string; status?: string };
export type Filters = { from: string; to: string };
export type Card = { id: string; label: string; value: string | null; basis: string; status: string; url: string; note: string; unit: 'money' | 'count' };
export type BankRow = { id: number | null; bank_id: number | null; cutoff: string; difference: string | null; status: string; url: string };
export type Scan = { status: string; url: string; total_candidates?: number; inspected?: number; needs_review?: number; has_more?: boolean; counts?: Record<string, number> };
export type Dashboard = { currency: string; cards: Card[]; bank_rows: BankRow[]; source_scan: Scan | null; links: { label: string; url: string }[]; bank_visible: boolean; cash_visible: boolean; coverage: string };
export type Props = { companies: Company[]; company: Company | null; today: string | null; filters: Filters; dashboard: Dashboard | null; errors?: Record<string, string> };
export const path = '/erp/accounting/dashboard';
export function dashboardUrl(company: number, filters: Filters): string {
    return `${path}?${new URLSearchParams({ company_id: String(company), from: filters.from, to: filters.to }).toString()}`;
}
export function basisLabel(basis: string, filters: Filters): string {
    if (basis === 'period') return `${filters.from} through ${filters.to}`;
    if (basis === 'cutoff') return `Balance at ${filters.to}`;
    if (basis === 'current') return `Current workflow · dates through ${filters.to}`;
    return 'Review source report';
}
