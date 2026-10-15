export type Company = { id: number; name: string; currency_code: string; status: string };
export type Customer = { id: number; company_id: number; customer_code: string | null; customer_type: string; name: string; business_name: string | null; contact_person: string | null; phone: string; email: string | null; credit_limit: string; payment_terms_days: number; is_active: boolean };
export type Contact = { id: number; name: string; designation: string | null; phone: string | null; email: string | null; is_primary: boolean };
export type Address = { id: number; address_type: string; label: string | null; address: string; postal_code: string | null; is_primary: boolean };
export const button = "inline-flex items-center justify-center rounded-lg border bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50";
export const input = "w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm";
