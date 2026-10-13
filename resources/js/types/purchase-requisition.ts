export type RequisitionCompany = { id: number; name: string; currency_code?: string | null };
export type RequisitionDepartment = { id: number; name: string };
export type RequisitionProduct = {
    id: number; name: string; sku: string | null; has_variants: boolean; purchase_price: string;
    variants: { id: number; name: string; sku: string | null; purchase_price: string | null }[];
};
export type Requisition = {
    id: number; company_id: number; company?: RequisitionCompany | null;
    department_id: number | null; department?: RequisitionDepartment | null;
    number: string; status: "draft" | "submitted" | "approved" | "rejected";
    requested_by: number; approved_by: number | null; required_at: string | null;
    submitted_at: string | null; approved_at: string | null; created_at: string;
    notes: string | null; revision: number; currency_code: string | null;
};
export type RequisitionItem = {
    id: number; product_id: number; product_variant_id: number | null;
    quantity: string; estimated_unit_price: string; notes: string | null;
    product?: { id: number; name: string; sku: string | null } | null;
    product_variant?: { id: number; name: string; sku: string | null } | null;
};
