export type SellerCompany = {
    id: number;
    name: string;
};

export type SellerDivision = {
    id: number;
    name: string;
};

export type SellerDistrict = {
    id: number;
    division_id?: number;
    name: string;

    division?: SellerDivision | null;
};

export type SellerDepot = {
    id: number;
    name: string;
    depot_code?: string | null;
    district_id?: number;

    district?: SellerDistrict | null;
};

export type SellerUpazila = {
    id: number;
    district_id?: number;
    name: string;
    name_bn?: string | null;
};

export type SellerDealer = {
    id: number;
    company_id: number;
    depot_id: number;
    upazila_id: number;

    dealer_code?: string | null;

    business_name: string;

    proprietor_name?: string | null;
    phone?: string | null;

    company?: SellerCompany | null;
    depot?: SellerDepot | null;
    upazila?: SellerUpazila | null;
};

export type SellerManager = {
    id: number;
    name: string;
    email?: string | null;
};

export type Seller = {
    id: number;

    company_id: number;
    dealer_id: number;

    seller_code?: string | null;

    business_name: string;
    proprietor_name: string;

    proprietor_photo?: string | null;

    seller_type:
        | "retailer"
        | "general_seller";

    phone: string;
    alternate_phone?: string | null;
    email?: string | null;

    trade_license_number?: string | null;
    nid_number?: string | null;
    tin_number?: string | null;
    vat_number?: string | null;

    business_address: string;
    postal_code?: string | null;

    credit_limit:
        | string
        | number;

    payment_terms_days:
        number;

    manager_user_id?:
        number | null;

    is_active: boolean;

    notes?: string | null;

    company?: SellerCompany | null;
    dealer?: SellerDealer | null;
    manager?: SellerManager | null;

    created_at?: string;
    updated_at?: string;
};

export type SellerFormDealer =
    SellerDealer;

export type SellerFormManager = {
    id: number;
    name: string;
    email?: string | null;
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
    from?: number | null;
    to?: number | null;
    links: PaginationLink[];
};