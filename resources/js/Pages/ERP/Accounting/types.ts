export type Company = {
    id: number;
    name: string;
    code: string;
    currency_code: string;
    timezone: string;
    status: string;
};

export type Configuration = {
    state: string;
    functional_currency: string;
    currency_precision: number;
    contract_version: string;
    recognition_policy: string;
    revision: number;
};

export type WorkspaceProps = {
    companies: Company[];
    company: Company | null;
    filters: { company_id: number | null };
    configuration: Configuration | null;
    issues: string[];
    canInitialize: boolean;
    postingAvailable: false;
};

export const panel = 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm';
export const button = 'inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50';

export function companyUrl(path: string, id: number | null): string {
    return id === null ? path : `${path}?company_id=${encodeURIComponent(String(id))}`;
}
