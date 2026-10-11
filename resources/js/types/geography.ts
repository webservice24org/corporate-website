export type Division = {
    id: number;

    name: string;
    name_bn: string | null;
    code: string | null;

    is_active: boolean;

    districts_count?: number;

    created_at?: string;
    updated_at?: string;
};

export type District = {
    id: number;

    division_id: number;

    name: string;
    name_bn: string | null;
    code: string | null;

    is_active: boolean;

    upazilas_count?: number;

    division?: Division | null;

    created_at?: string;
    updated_at?: string;
};

export type Upazila = {
    id: number;

    district_id: number;

    name: string;
    name_bn: string | null;
    code: string | null;

    is_active: boolean;

    district?: District | null;

    created_at?: string;
    updated_at?: string;
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