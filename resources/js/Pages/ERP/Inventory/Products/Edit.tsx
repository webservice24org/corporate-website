import ERPLayout from "@/Layouts/erp-layout";
import ProductForm from "./ProductForm";
import type {
    BrandOption,
    CompanyOption,
    Product,
    ProductCategoryOption,
    ProductFormData,
    UnitOption,
} from "@/types/product";
import { Head, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";

interface Props {
    product: Product;
    companies: CompanyOption[];
    categories: ProductCategoryOption[];
    brands: BrandOption[];
    units: UnitOption[];
}

export default function Edit({ product, companies, categories, brands, units }: Props) {
    const form = useForm<ProductFormData>({
        company_id: product.company_id,
        product_category_id: product.product_category_id ?? "",
        brand_id: product.brand_id ?? "",
        unit_id: product.unit_id,
        product_type: product.product_type,
        name: product.name,
        product_code: product.product_code ?? "",
        sku: product.sku ?? "",
        barcode: product.barcode ?? "",
        featured_image: null,
        gallery_images: [],
        remove_featured_image: false,
        remove_gallery_images: [],
        short_description: product.short_description ?? "",
        description: product.description ?? "",
        purchase_price: product.purchase_price,
        selling_price: product.selling_price,
        track_inventory: product.track_inventory,
        allow_negative_stock: product.allow_negative_stock,
        reorder_level: product.reorder_level,
        minimum_stock: product.minimum_stock,
        has_variants: product.has_variants,
        is_active: product.is_active,
        variants: (product.variants ?? []).map((variant) => ({
            id: variant.id,
            name: variant.name,
            sku: variant.sku ?? "",
            barcode: variant.barcode ?? "",
            attributes: variant.attributes ?? {},
            purchase_price: variant.purchase_price ?? "",
            selling_price: variant.selling_price ?? "",
            is_active: variant.is_active,
        })),
    });

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            _method: "put",
        }));

        form.post(`/erp/inventory/products/${product.id}`, {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <ERPLayout>
            <Head title={`Edit ${product.name}`} />

            <ProductForm
                mode="edit"
                data={form.data}
                setData={form.setData}
                companies={companies}
                categories={categories}
                brands={brands}
                units={units}
                errors={form.errors}
                processing={form.processing}
                submitLabel="Update Product"
                onSubmit={handleSubmit}
                existingFeaturedImageUrl={product.featured_image_url}
                existingGalleryImages={product.gallery_image_urls ?? []}
            />
        </ERPLayout>
    );
}
