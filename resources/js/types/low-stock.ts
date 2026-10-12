/** Inertia contract for ERP/Inventory/LowStock/Index. */
export type LowStockStatus =
    | 'out_of_stock'
    | 'critical'
    | 'low_stock'
    | 'healthy';

export type LowStockThresholdSource = 'warehouse' | 'product';

export interface LowStockRow {
    company_id: number;
    warehouse_id: number;
    warehouse_name: string;
    warehouse_code: string | null;
    product_id: number;
    product_name: string;
    product_sku: string | null;
    product_variant_id: number | null;
    variant_name: string | null;
    variant_sku: string | null;
    variant_key: number;
    quantity_on_hand: string;
    quantity_reserved: string;
    quantity_available: string;
    minimum_stock: string;
    reorder_level: string;
    suggested_reorder_quantity: string;
    status: LowStockStatus;
    threshold_source: LowStockThresholdSource;
}

export interface LowStockSummary {
    total: number;
    out_of_stock: number;
    critical: number;
    low_stock: number;
    healthy: number;
}

export interface LowStockFilters {
    company_id: string;
    warehouse_id: string;
    product_id: string;
    category_id: string;
    status: LowStockStatus | '';
    include_healthy: boolean;
}

export interface LowStockCompanyOption {
    id: number;
    name: string;
}

export interface LowStockWarehouseOption {
    id: number;
    company_id: number;
    code: string | null;
    name: string;
}

export interface LowStockProductOption {
    id: number;
    company_id: number;
    product_category_id: number | null;
    name: string;
    sku: string | null;
}

export interface LowStockCategoryOption {
    id: number;
    name: string;
}

export interface LowStockStatusOption {
    value: LowStockStatus;
    label: string;
}

export interface LowStockPageProps {
    rows: LowStockRow[];
    summary: LowStockSummary;
    filters: LowStockFilters;
    companies: LowStockCompanyOption[];
    warehouses: LowStockWarehouseOption[];
    products: LowStockProductOption[];
    categories: LowStockCategoryOption[];
    statuses: LowStockStatusOption[];
    canManageReorderSettings: boolean;
}
