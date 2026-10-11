export type CompanyOption = {
    id: number;
    name: string;
};

export type ProductCategoryOption = {
    id: number;
    company_id: number;
    parent_id: number | null;
    name: string;
};

export type BrandOption = {
    id: number;
    company_id: number;
    name: string;
};

export type UnitOption = {
    id: number;
    company_id: number;
    name: string;
    short_name: string;
    decimal_places: number;
};

export type ProductVariant = {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    attributes: Record<string, string> | null;
    purchase_price: string | number | null;
    selling_price: string | number | null;
    is_active: boolean;
};

export type ProductVariantFormData = {
    id?: number;
    name: string;
    sku: string;
    barcode: string;
    attributes: Record<string, string>;
    purchase_price: string | number;
    selling_price: string | number;
    is_active: boolean;
};

export type GalleryImage = {
    path: string;
    url: string;
};

export type Product = {
    id: number;
    company_id: number;
    product_category_id: number | null;
    brand_id: number | null;
    unit_id: number;
    product_type: "physical" | "service";
    name: string;
    product_code: string | null;
    sku: string | null;
    barcode: string | null;
    featured_image: string | null;
    featured_image_url: string | null;
    gallery_images: string[] | null;
    gallery_image_urls: GalleryImage[];
    short_description: string | null;
    description: string | null;
    purchase_price: string | number;
    selling_price: string | number;
    track_inventory: boolean;
    allow_negative_stock: boolean;
    reorder_level: string | number;
    minimum_stock: string | number;
    has_variants: boolean;
    is_active: boolean;
    variants_count?: number;
    variants?: ProductVariant[];
    company?: CompanyOption | null;
    category?: { id: number; name: string } | null;
    brand?: { id: number; name: string } | null;
    unit?: UnitOption | null;
};

export type ProductFormData = {
    company_id: number | "";
    product_category_id: number | "";
    brand_id: number | "";
    unit_id: number | "";
    product_type: "physical" | "service";
    name: string;
    product_code: string;
    sku: string;
    barcode: string;
    featured_image: File | null;
    gallery_images: File[];
    remove_featured_image: boolean;
    remove_gallery_images: string[];
    short_description: string;
    description: string;
    purchase_price: string | number;
    selling_price: string | number;
    track_inventory: boolean;
    allow_negative_stock: boolean;
    reorder_level: string | number;
    minimum_stock: string | number;
    has_variants: boolean;
    is_active: boolean;
    variants: ProductVariantFormData[];
};
