import ERPLayout from "@/Layouts/erp-layout";
import ProductForm from "./ProductForm";
import type {
    BrandOption,
    CompanyOption,
    ProductCategoryOption,
    ProductFormData,
    UnitOption,
} from "@/types/product";
import { Head, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";

interface Props {
    companies: CompanyOption[];
    categories: ProductCategoryOption[];
    brands: BrandOption[];
    units: UnitOption[];
}

export default function Create({ companies, categories, brands, units }: Props) {
    const form = useForm<ProductFormData>({
        company_id: "",
        product_category_id: "",
        brand_id: "",
        unit_id: "",
        product_type: "physical",
        name: "",
        product_code: "",
        sku: "",
        barcode: "",
        featured_image: null,
        gallery_images: [],
        remove_featured_image: false,
        remove_gallery_images: [],
        short_description: "",
        description: "",
        purchase_price: "0",
        selling_price: "0",
        track_inventory: true,
        allow_negative_stock: false,
        reorder_level: "0",
        minimum_stock: "0",
        has_variants: false,
        is_active: true,
        variants: [],
    });

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.post("/erp/inventory/products", {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <ERPLayout>
            <Head title="Add Product" />

            <ProductForm
                mode="create"
                data={form.data}
                setData={form.setData}
                companies={companies}
                categories={categories}
                brands={brands}
                units={units}
                errors={form.errors}
                processing={form.processing}
                submitLabel="Publish Product"
                onSubmit={handleSubmit}
            />
        </ERPLayout>
    );
}
