export type SupplierCompany = { id: number; name: string };
export type Supplier = {
    id: number;
    company_id: number;
    company?: SupplierCompany | null;
    code: string;
    name: string;
    currency_code: string;
    status: "active" | "inactive";
    email: string | null;
    phone: string | null;
    tax_number: string | null;
    payment_terms_days: number;
    notes: string | null;
};
export type SupplierContact = {
    id: number;
    name: string;
    job_title: string | null;
    email: string | null;
    phone: string | null;
    is_primary: boolean;
};
export type SupplierAddress = {
    id: number;
    label: string;
    address_line_1: string;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    is_default: boolean;
};
