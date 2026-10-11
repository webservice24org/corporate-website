export type CompanyOption = {
    id: number;
    name: string;
};

export type DivisionOption = {
    id: number;
    name: string;
    name_bn?: string | null;
};

export type DistrictOption = {
    id: number;
    division_id: number;
    name: string;
    name_bn?: string | null;
};

export type UserSummary = {
    id: number;
    name: string;
    email?: string | null;
};

export type CompanySummary = {
    id: number;
    name: string;
};

export type DivisionSummary = {
    id: number;
    name: string;
};

export type DistrictSummary = {
    id: number;
    name: string;
};

export type DepotSummary = {
    id: number;
    name: string;
    depot_code: string | null;
};

export type DepotApplicationDocument = {
    id: number;
    depot_application_id: number;
    document_type: string;
    disk: string;
    path: string;
    original_name: string;
    mime_type: string | null;
    file_size: number | null;
    verified_at: string | null;
    verified_by?: UserSummary | null;
};

export type DepotApplication = {
    id: number;

    company_id: number;
    division_id: number | null;
    district_id: number | null;
    depot_id: number | null;

    business_name: string;
    proprietor_name: string;

    phone: string;
    alternate_phone: string | null;
    email: string | null;

    trade_license_number: string | null;
    nid_number: string | null;
    tin_number: string | null;
    vat_number: string | null;

    business_address: string;
    postal_code: string | null;

    years_in_business: number | null;

    requested_credit_limit:
        string | number | null;

    business_description: string | null;

    status:
        | "pending"
        | "under_review"
        | "approved"
        | "rejected";

    reviewed_at: string | null;
    approved_at: string | null;
    rejected_at: string | null;

    admin_notes: string | null;
    rejection_reason: string | null;

    created_at: string;
    updated_at: string;

    company?: CompanySummary | null;
    division?: DivisionSummary | null;
    district?: DistrictSummary | null;
    depot?: DepotSummary | null;

    documents?: DepotApplicationDocument[];

    reviewed_by?: UserSummary | null;
    approved_by?: UserSummary | null;
    rejected_by?: UserSummary | null;
};

export type Depot = {
    id: number;

    company_id: number;
    division_id: number | null;
    district_id: number | null;

    depot_code: string | null;
    name: string;

    proprietor_name: string | null;

    phone: string | null;
    alternate_phone: string | null;
    email: string | null;

    trade_license_number: string | null;
    nid_number: string | null;
    tin_number: string | null;
    vat_number: string | null;

    address: string | null;
    postal_code: string | null;

    credit_limit: string | number;
    payment_terms_days: number;

    manager_user_id: number | null;

    is_active: boolean;

    notes: string | null;

    created_at: string;
    updated_at: string;

    company?: CompanySummary | null;
    division?: DivisionSummary | null;
    district?: DistrictSummary | null;
    manager?: UserSummary | null;

    applications?: DepotApplication[];
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};