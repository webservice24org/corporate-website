import type {
    BrandOption,
    CompanyOption,
    GalleryImage,
    ProductCategoryOption,
    ProductFormData,
    ProductVariantFormData,
    UnitOption,
} from "@/types/product";
import { ImagePlus, Package, Plus, Trash2, Upload, X } from "lucide-react";
import { useMemo } from "react";
import type { FormEvent, ReactNode } from "react";

interface Props {
    mode: "create" | "edit";
    data: ProductFormData;
    setData: <K extends keyof ProductFormData>(
        key: K,
        value: ProductFormData[K]
    ) => void;
    companies: CompanyOption[];
    categories: ProductCategoryOption[];
    brands: BrandOption[];
    units: UnitOption[];
    errors: Partial<Record<keyof ProductFormData, string>>;
    processing: boolean;
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    existingFeaturedImageUrl?: string | null;
    existingGalleryImages?: GalleryImage[];
}

const inputClasses =
    "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-slate-400 dark:border-slate-800 dark:bg-slate-950";

const textareaClasses =
    "w-full rounded-md border border-slate-200 bg-white p-3 text-sm outline-none focus:border-slate-400 dark:border-slate-800 dark:bg-slate-950";

export default function ProductForm({
    mode,
    data,
    setData,
    companies,
    categories,
    brands,
    units,
    errors,
    processing,
    submitLabel,
    onSubmit,
    existingFeaturedImageUrl = null,
    existingGalleryImages = [],
}: Props) {
    const companyId = Number(data.company_id || 0);

    const availableCategories = categories.filter(
        (category) => category.company_id === companyId
    );
    const availableBrands = brands.filter(
        (brand) => brand.company_id === companyId
    );
    const availableUnits = units.filter(
        (unit) => unit.company_id === companyId
    );

    const featuredPreview = useMemo(() => {
        if (data.featured_image) {
            return URL.createObjectURL(data.featured_image);
        }

        if (!data.remove_featured_image) {
            return existingFeaturedImageUrl;
        }

        return null;
    }, [data.featured_image, data.remove_featured_image, existingFeaturedImageUrl]);

    const newGalleryPreviews = useMemo(
        () =>
            data.gallery_images.map((file) => ({
                file,
                url: URL.createObjectURL(file),
            })),
        [data.gallery_images]
    );

    const visibleExistingGallery = existingGalleryImages.filter(
        (image) => !data.remove_gallery_images.includes(image.path)
    );

    const addVariant = () => {
        setData("variants", [
            ...data.variants,
            {
                name: "",
                sku: "",
                barcode: "",
                attributes: {},
                purchase_price: "",
                selling_price: "",
                is_active: true,
            },
        ]);
    };

    const updateVariant = (
        index: number,
        payload: Partial<ProductVariantFormData>
    ) => {
        setData(
            "variants",
            data.variants.map((variant, currentIndex) =>
                currentIndex === index
                    ? { ...variant, ...payload }
                    : variant
            )
        );
    };

    const removeVariant = (index: number) => {
        setData(
            "variants",
            data.variants.filter((_, currentIndex) => currentIndex !== index)
        );
    };

    const handleFeaturedImage = (file: File | null) => {
        setData("featured_image", file);

        if (file) {
            setData("remove_featured_image", false);
        }
    };

    const removeFeaturedImage = () => {
        setData("featured_image", null);
        setData("remove_featured_image", true);
    };

    const addGalleryFiles = (files: FileList | null) => {
        if (!files) return;

        const nextFiles = Array.from(files);
        const remainingSlots = Math.max(
            0,
            12 - visibleExistingGallery.length - data.gallery_images.length
        );

        setData("gallery_images", [
            ...data.gallery_images,
            ...nextFiles.slice(0, remainingSlots),
        ]);
    };

    const removeNewGalleryImage = (index: number) => {
        setData(
            "gallery_images",
            data.gallery_images.filter(
                (_, currentIndex) => currentIndex !== index
            )
        );
    };

    const removeExistingGalleryImage = (path: string) => {
        setData("remove_gallery_images", [
            ...data.remove_gallery_images,
            path,
        ]);
    };

    return (
        <form onSubmit={onSubmit} className="space-y-6">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold">
                        {mode === "edit" ? "Edit Product" : "Add New Product"}
                    </h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Create catalog information, pricing, stock behavior, variants and media.
                    </p>
                </div>
            </div>

            <div className="grid min-w-0 gap-6 2xl:grid-cols-[minmax(0,1fr)_360px]">
                <main className="min-w-0 space-y-6">
                    <Card title="Product name">
                        <Field error={errors.name}>
                            <input
                                className="h-12 w-full rounded-md border border-slate-200 bg-white px-4 text-lg font-medium outline-none focus:border-slate-400 dark:border-slate-800 dark:bg-slate-950"
                                value={data.name}
                                required
                                placeholder="Product name"
                                onChange={(event) =>
                                    setData("name", event.target.value)
                                }
                            />
                        </Field>
                    </Card>

                    <Card title="Product description">
                        <Field error={errors.description}>
                            <textarea
                                rows={12}
                                className={textareaClasses}
                                value={data.description}
                                placeholder="Write the full product description..."
                                onChange={(event) =>
                                    setData("description", event.target.value)
                                }
                            />
                        </Field>
                    </Card>

                    <Card title="Product data">
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <Field label="Product type" error={errors.product_type}>
                                <select
                                    className={inputClasses}
                                    value={data.product_type}
                                    onChange={(event) => {
                                        const value = event.target.value as ProductFormData["product_type"];
                                        setData("product_type", value);

                                        if (value === "service") {
                                            setData("track_inventory", false);
                                            setData("allow_negative_stock", false);
                                            setData("reorder_level", "0");
                                            setData("minimum_stock", "0");
                                        }
                                    }}
                                >
                                    <option value="physical">Physical product</option>
                                    <option value="service">Service</option>
                                </select>
                            </Field>

                            <Field label="Purchase price" error={errors.purchase_price}>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className={inputClasses}
                                    value={data.purchase_price}
                                    onChange={(event) =>
                                        setData("purchase_price", event.target.value)
                                    }
                                />
                            </Field>

                            <Field label="Selling price" error={errors.selling_price}>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className={inputClasses}
                                    value={data.selling_price}
                                    onChange={(event) =>
                                        setData("selling_price", event.target.value)
                                    }
                                />
                            </Field>

                            <Field label="Product code" error={errors.product_code}>
                                <input
                                    className={inputClasses}
                                    value={data.product_code}
                                    placeholder="Internal product code"
                                    onChange={(event) =>
                                        setData("product_code", event.target.value)
                                    }
                                />
                            </Field>

                            <Field label="SKU" error={errors.sku}>
                                <input
                                    className={inputClasses}
                                    value={data.sku}
                                    placeholder="SKU"
                                    onChange={(event) =>
                                        setData("sku", event.target.value)
                                    }
                                />
                            </Field>

                            <Field label="Barcode" error={errors.barcode}>
                                <input
                                    className={inputClasses}
                                    value={data.barcode}
                                    placeholder="Barcode"
                                    onChange={(event) =>
                                        setData("barcode", event.target.value)
                                    }
                                />
                            </Field>
                        </div>

                        {data.product_type === "physical" && (
                            <div className="mt-6 border-t border-slate-200 pt-5 dark:border-slate-800">
                                <h3 className="mb-4 text-sm font-semibold">Inventory</h3>

                                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <Toggle
                                        label="Track inventory"
                                        checked={data.track_inventory}
                                        onChange={(value) =>
                                            setData("track_inventory", value)
                                        }
                                    />

                                    <Toggle
                                        label="Allow negative stock"
                                        checked={data.allow_negative_stock}
                                        onChange={(value) =>
                                            setData("allow_negative_stock", value)
                                        }
                                    />

                                    <Field label="Reorder level" error={errors.reorder_level}>
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.0001"
                                            className={inputClasses}
                                            value={data.reorder_level}
                                            onChange={(event) =>
                                                setData("reorder_level", event.target.value)
                                            }
                                        />
                                    </Field>

                                    <Field label="Minimum stock" error={errors.minimum_stock}>
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.0001"
                                            className={inputClasses}
                                            value={data.minimum_stock}
                                            onChange={(event) =>
                                                setData("minimum_stock", event.target.value)
                                            }
                                        />
                                    </Field>
                                </div>
                            </div>
                        )}
                    </Card>

                    <Card title="Variants">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <Toggle
                                label="This product has variants"
                                checked={data.has_variants}
                                onChange={(value) => {
                                    setData("has_variants", value);

                                    if (value && data.variants.length === 0) {
                                        addVariant();
                                    }
                                }}
                            />

                            {data.has_variants && (
                                <button
                                    type="button"
                                    onClick={addVariant}
                                    className="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-slate-200 px-3 text-sm font-medium dark:border-slate-800"
                                >
                                    <Plus className="h-4 w-4" />
                                    Add variant
                                </button>
                            )}
                        </div>

                        {data.has_variants && (
                            <div className="mt-5 space-y-4">
                                {data.variants.map((variant, index) => (
                                    <div
                                        key={variant.id ?? index}
                                        className="rounded-lg border border-slate-200 p-4 dark:border-slate-800"
                                    >
                                        <div className="mb-4 flex items-center justify-between">
                                            <div className="text-sm font-semibold">
                                                Variant {index + 1}
                                            </div>

                                            <button
                                                type="button"
                                                onClick={() => removeVariant(index)}
                                                className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-red-200 text-red-600"
                                                title="Remove variant"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        </div>

                                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                            <Field label="Variant name">
                                                <input
                                                    className={inputClasses}
                                                    value={variant.name}
                                                    placeholder="e.g. Red / Large"
                                                    onChange={(event) =>
                                                        updateVariant(index, {
                                                            name: event.target.value,
                                                        })
                                                    }
                                                />
                                            </Field>

                                            <Field label="SKU">
                                                <input
                                                    className={inputClasses}
                                                    value={variant.sku}
                                                    onChange={(event) =>
                                                        updateVariant(index, {
                                                            sku: event.target.value,
                                                        })
                                                    }
                                                />
                                            </Field>

                                            <Field label="Barcode">
                                                <input
                                                    className={inputClasses}
                                                    value={variant.barcode}
                                                    onChange={(event) =>
                                                        updateVariant(index, {
                                                            barcode: event.target.value,
                                                        })
                                                    }
                                                />
                                            </Field>

                                            <Field label="Purchase price override">
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    className={inputClasses}
                                                    value={variant.purchase_price}
                                                    onChange={(event) =>
                                                        updateVariant(index, {
                                                            purchase_price: event.target.value,
                                                        })
                                                    }
                                                />
                                            </Field>

                                            <Field label="Selling price override">
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    className={inputClasses}
                                                    value={variant.selling_price}
                                                    onChange={(event) =>
                                                        updateVariant(index, {
                                                            selling_price: event.target.value,
                                                        })
                                                    }
                                                />
                                            </Field>

                                            <div className="flex items-end">
                                                <Toggle
                                                    label="Active variant"
                                                    checked={variant.is_active}
                                                    onChange={(value) =>
                                                        updateVariant(index, {
                                                            is_active: value,
                                                        })
                                                    }
                                                />
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>

                    <Card title="Product short description">
                        <Field error={errors.short_description}>
                            <textarea
                                rows={6}
                                className={textareaClasses}
                                value={data.short_description}
                                placeholder="A short summary for product cards and quick views..."
                                onChange={(event) =>
                                    setData("short_description", event.target.value)
                                }
                            />
                        </Field>
                    </Card>
                </main>

                <aside className="min-w-0 space-y-6 2xl:sticky 2xl:top-6 2xl:self-start">
                    <SidebarCard title="Publish">
                        <div className="space-y-4">
                            <Toggle
                                label="Active product"
                                checked={data.is_active}
                                onChange={(value) =>
                                    setData("is_active", value)
                                }
                            />

                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex h-10 w-full items-center justify-center rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50 dark:bg-white dark:text-slate-950"
                            >
                                {processing ? "Saving..." : submitLabel}
                            </button>
                        </div>
                    </SidebarCard>

                    <SidebarCard title="Product organization">
                        <div className="space-y-4">
                            <Field label="Company" error={errors.company_id}>
                                <select
                                    className={inputClasses}
                                    value={data.company_id}
                                    required
                                    onChange={(event) => {
                                        const value = event.target.value
                                            ? Number(event.target.value)
                                            : "";

                                        setData("company_id", value);
                                        setData("product_category_id", "");
                                        setData("brand_id", "");
                                        setData("unit_id", "");
                                    }}
                                >
                                    <option value="">Select company</option>
                                    {companies.map((company) => (
                                        <option key={company.id} value={company.id}>
                                            {company.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="Category" error={errors.product_category_id}>
                                <select
                                    className={inputClasses}
                                    value={data.product_category_id}
                                    disabled={!data.company_id}
                                    onChange={(event) =>
                                        setData(
                                            "product_category_id",
                                            event.target.value
                                                ? Number(event.target.value)
                                                : ""
                                        )
                                    }
                                >
                                    <option value="">No category</option>
                                    {availableCategories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="Brand" error={errors.brand_id}>
                                <select
                                    className={inputClasses}
                                    value={data.brand_id}
                                    disabled={!data.company_id}
                                    onChange={(event) =>
                                        setData(
                                            "brand_id",
                                            event.target.value
                                                ? Number(event.target.value)
                                                : ""
                                        )
                                    }
                                >
                                    <option value="">No brand</option>
                                    {availableBrands.map((brand) => (
                                        <option key={brand.id} value={brand.id}>
                                            {brand.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="Unit" error={errors.unit_id}>
                                <select
                                    className={inputClasses}
                                    value={data.unit_id}
                                    required
                                    disabled={!data.company_id}
                                    onChange={(event) =>
                                        setData(
                                            "unit_id",
                                            event.target.value
                                                ? Number(event.target.value)
                                                : ""
                                        )
                                    }
                                >
                                    <option value="">Select unit</option>
                                    {availableUnits.map((unit) => (
                                        <option key={unit.id} value={unit.id}>
                                            {unit.name} ({unit.short_name})
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                    </SidebarCard>

                    <SidebarCard title="Product image">
                        <div className="space-y-3">
                            <div className="flex aspect-square items-center justify-center overflow-hidden rounded-lg border border-dashed border-slate-300 bg-slate-50 dark:border-slate-700 dark:bg-slate-900">
                                {featuredPreview ? (
                                    <img
                                        src={featuredPreview}
                                        alt="Product featured preview"
                                        className="h-full w-full object-cover"
                                    />
                                ) : (
                                    <div className="text-center text-slate-400">
                                        <Package className="mx-auto h-10 w-10" />
                                        <div className="mt-2 text-xs">No featured image</div>
                                    </div>
                                )}
                            </div>

                            <label className="inline-flex h-10 w-full cursor-pointer items-center justify-center gap-2 rounded-md border border-slate-200 text-sm font-medium dark:border-slate-800">
                                <Upload className="h-4 w-4" />
                                {featuredPreview ? "Replace image" : "Set product image"}
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                    className="hidden"
                                    onChange={(event) =>
                                        handleFeaturedImage(event.target.files?.[0] ?? null)
                                    }
                                />
                            </label>

                            {featuredPreview && (
                                <button
                                    type="button"
                                    onClick={removeFeaturedImage}
                                    className="w-full text-sm font-medium text-red-600"
                                >
                                    Remove product image
                                </button>
                            )}

                            {errors.featured_image && (
                                <p className="text-xs text-red-600">{errors.featured_image}</p>
                            )}
                        </div>
                    </SidebarCard>

                    <SidebarCard title="Product gallery">
                        <div className="grid grid-cols-3 gap-2">
                            {visibleExistingGallery.map((image) => (
                                <GalleryTile
                                    key={image.path}
                                    url={image.url}
                                    onRemove={() => removeExistingGalleryImage(image.path)}
                                />
                            ))}

                            {newGalleryPreviews.map((image, index) => (
                                <GalleryTile
                                    key={`${image.file.name}-${index}`}
                                    url={image.url}
                                    onRemove={() => removeNewGalleryImage(index)}
                                />
                            ))}

                            {visibleExistingGallery.length + data.gallery_images.length < 12 && (
                                <label className="flex aspect-square cursor-pointer items-center justify-center rounded-md border border-dashed border-slate-300 bg-slate-50 text-slate-400 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800">
                                    <ImagePlus className="h-5 w-5" />
                                    <input
                                        type="file"
                                        multiple
                                        accept="image/jpeg,image/png,image/webp,image/gif"
                                        className="hidden"
                                        onChange={(event) => {
                                            addGalleryFiles(event.target.files);
                                            event.currentTarget.value = "";
                                        }}
                                    />
                                </label>
                            )}
                        </div>

                        <p className="mt-3 text-xs text-slate-500">
                            Up to 12 gallery images. Maximum 5 MB per image.
                        </p>

                        {errors.gallery_images && (
                            <p className="mt-2 text-xs text-red-600">{errors.gallery_images}</p>
                        )}
                    </SidebarCard>
                </aside>
            </div>
        </form>
    );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <div className="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h2 className="font-semibold">{title}</h2>
            </div>
            <div className="p-5">{children}</div>
        </section>
    );
}

function SidebarCard({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 className="text-sm font-semibold">{title}</h2>
            </div>
            <div className="p-4">{children}</div>
        </section>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <label className="block">
            {label && (
                <span className="mb-1.5 block text-sm font-medium">{label}</span>
            )}
            {children}
            {error && (
                <span className="mt-1 block text-xs text-red-600">{error}</span>
            )}
        </label>
    );
}

function Toggle({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <label className="inline-flex items-center gap-2 text-sm font-medium">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="h-4 w-4 rounded border-slate-300"
            />
            {label}
        </label>
    );
}

function GalleryTile({ url, onRemove }: { url: string; onRemove: () => void }) {
    return (
        <div className="group relative aspect-square overflow-hidden rounded-md border border-slate-200 dark:border-slate-800">
            <img src={url} alt="Product gallery" className="h-full w-full object-cover" />
            <button
                type="button"
                onClick={onRemove}
                title="Remove image"
                className="absolute right-1 top-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-black/70 text-white opacity-0 transition group-hover:opacity-100"
            >
                <X className="h-3.5 w-3.5" />
            </button>
        </div>
    );
}
