export type DealerCompany = {
    id: number;
    name: string;
};

export type DealerDivision = {
    id: number;
    name: string;
    name_bn?: string | null;
};

export type DealerDistrict = {
    id: number;
    division_id: number;
    name: string;
    name_bn?: string | null;

    division?: DealerDivision | null;
};

export type DealerDepot = {
    id: number;
    company_id?: number;

    district_id: number;

    name: string;
    depot_code: string | null;

    company?: DealerCompany | null;
    district?: DealerDistrict | null;
};

export type DealerUpazila = {
    id: number;
    district_id: number;

    name: string;
    name_bn: string | null;
    code?: string | null;

    district?: DealerDistrict | null;
};

export type DealerApplicationDocument = {
    id: number;

    dealer_application_id: number;

    document_type: string;

    original_name: string;
    mime_type: string | null;
    file_size: number | null;

    verified_at: string | null;

    verified_by?: {
        id: number;
        name: string;
        email?: string | null;
    } | null;
};

export type DealerApplication = {
    id: number;

    public_token: string;

    company_id: number;
    depot_id: number;
    upazila_id: number;

    dealer_id: number | null;

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

    company?: DealerCompany | null;
    depot?: DealerDepot | null;
    upazila?: DealerUpazila | null;

    documents?: DealerApplicationDocument[];

    dealer?: {
        id: number;
        business_name: string;
        dealer_code: string | null;
    } | null;
};

export type Dealer = {
    id: number;

    company_id: number;
    depot_id: number;
    upazila_id: number;

    dealer_code: string | null;

    business_name: string;
    proprietor_name: string | null;
    proprietor_photo?: string | null;

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

    is_active: boolean;

    notes: string | null;

    created_at: string;

    company?: DealerCompany | null;
    depot?: DealerDepot | null;
    upazila?: DealerUpazila | null;

    applications?: DealerApplication[];

    manager?: {
        id: number;
        name: string;
        email?: string | null;
    } | null;

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

