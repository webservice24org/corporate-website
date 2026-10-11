import { Head, router, useForm } from "@inertiajs/react";
import { Pencil, Plus, Search, Trash2, X } from "lucide-react";
import { useState } from "react";
import type { ReactNode } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";

function FieldError({
    error,
    children,
}: {
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-1">
            {children}
            {error && <p className="text-xs text-red-500">{error}</p>}
        </div>
    );
}

type Company = {
    id: number;
    name: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Brand = {
    id: number;
    company_id: number;
    name: string;
    code: string | null;
    description: string | null;
    is_active: boolean;
    company?: Company | null;
};

type BrandFormData = {
    company_id: number | "";
    name: string;
    code: string;
    description: string;
    is_active: boolean;
};

type Props = {
    brands: {
        data: Brand[];
        links: PaginationLink[];
    };
    companies: Company[];
    filters?: {
        search?: string;
        company_id?: string | number;
    };
};

export default function Index({ brands, companies, filters }: Props) {
    const { can } = useAuthorization();

    const [editing, setEditing] = useState<Brand | null>(null);
    const [deletingBrand, setDeletingBrand] = useState<Brand | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const [search, setSearch] = useState(filters?.search ?? "");
    const [companyFilter, setCompanyFilter] = useState(
        filters?.company_id ?? ""
    );

    const form = useForm<BrandFormData>({
        company_id: "",
        name: "",
        code: "",
        description: "",
        is_active: true,
    });

    const resetForm = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
    };

    const startEdit = (brand: Brand) => {
        setEditing(brand);
        form.clearErrors();
        form.setData({
            company_id: brand.company_id,
            name: brand.name,
            code: brand.code ?? "",
            description: brand.description ?? "",
            is_active: brand.is_active,
        });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (editing) {
            form.put(`/erp/inventory/brands/${editing.id}`, {
                preserveScroll: true,
                onSuccess: resetForm,
            });
            return;
        }

        form.post("/erp/inventory/brands", {
            preserveScroll: true,
            onSuccess: resetForm,
        });
    };

    const confirmDelete = () => {
        if (!deletingBrand) return;

        setIsDeleting(true);
        router.delete(`/erp/inventory/brands/${deletingBrand.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setIsDeleting(false);
                setDeletingBrand(null);
            },
        });
    };

    const applyFilters = () => {
        router.get(
            "/erp/inventory/brands",
            {
                search: search || undefined,
                company_id: companyFilter || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Brands" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">Brands</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Manage product brands by company.
                    </p>
                </div>

                <div className="grid gap-6 xl:grid-cols-[1fr_380px]">
                    <section className="space-y-4">
                        <div className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-[1fr_220px_auto] dark:border-slate-800 dark:bg-slate-950">
                            <div className="relative">
                                <Search className="absolute left-3 top-3 h-4 w-4 text-slate-400" />
                                <input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search brands"
                                    className="h-10 w-full rounded-md border border-slate-200 bg-white pl-9 pr-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                />
                            </div>

                            <select
                                value={companyFilter}
                                onChange={(e) => setCompanyFilter(e.target.value)}
                                className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            >
                                <option value="">All Companies</option>
                                {companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>

                            <button
                                type="button"
                                onClick={applyFilters}
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm font-medium dark:border-slate-800"
                            >
                                Apply
                            </button>
                        </div>

                        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[760px] text-left text-sm">
                                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                                        <tr>
                                            <th className="px-5 py-3">Brand</th>
                                            <th className="px-5 py-3">Code</th>
                                            <th className="px-5 py-3">Company</th>
                                            <th className="px-5 py-3">Status</th>
                                            <th className="px-5 py-3 text-right">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {brands.data.map((brand) => (
                                            <tr key={brand.id}>
                                                <td className="px-5 py-4 font-medium">
                                                    {brand.name}
                                                </td>
                                                <td className="px-5 py-4">
                                                    {brand.code ?? "—"}
                                                </td>
                                                <td className="px-5 py-4">
                                                    {brand.company?.name ?? "—"}
                                                </td>
                                                <td className="px-5 py-4">
                                                    {brand.is_active ? "Active" : "Inactive"}
                                                </td>
                                                <td className="px-5 py-4">
                                                    <div className="flex justify-end gap-2">
                                                        {can("erp.brands.edit") && (
                                                            <button
                                                                type="button"
                                                                title="Edit Brand"
                                                                onClick={() => startEdit(brand)}
                                                                className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                            >
                                                                <Pencil className="h-4 w-4" />
                                                            </button>
                                                        )}

                                                        {can("erp.brands.delete") && (
                                                            <button
                                                                type="button"
                                                                title="Delete Brand"
                                                                onClick={() => setDeletingBrand(brand)}
                                                                className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950/30"
                                                            >
                                                                <Trash2 className="h-4 w-4" />
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}

                                        {brands.data.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="px-5 py-12 text-center text-slate-500"
                                                >
                                                    No brands found.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
                        <form onSubmit={submit}>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Plus className="h-4 w-4" />
                                    <h2 className="font-semibold">
                                        {editing ? "Edit Brand" : "Add Brand"}
                                    </h2>
                                </div>

                                {editing && (
                                    <button
                                        type="button"
                                        onClick={resetForm}
                                        className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                                    >
                                        <X className="h-4 w-4" />
                                    </button>
                                )}
                            </div>

                            <div className="mt-5 space-y-4">
                                <FieldError error={form.errors.company_id}>
                                    <select
                                        value={form.data.company_id}
                                        onChange={(e) =>
                                            form.setData(
                                                "company_id",
                                                e.target.value
                                                    ? Number(e.target.value)
                                                    : ""
                                            )
                                        }
                                        className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                    >
                                        <option value="">Select Company</option>
                                        {companies.map((company) => (
                                            <option key={company.id} value={company.id}>
                                                {company.name}
                                            </option>
                                        ))}
                                    </select>
                                </FieldError>

                                <FieldError error={form.errors.name}>
                                    <input
                                        type="text"
                                        placeholder="Brand Name"
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData("name", e.target.value)
                                        }
                                        className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                    />
                                </FieldError>

                                <FieldError error={form.errors.code}>
                                    <input
                                        type="text"
                                        placeholder="Brand Code (Optional)"
                                        value={form.data.code}
                                        onChange={(e) =>
                                            form.setData("code", e.target.value)
                                        }
                                        className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                    />
                                </FieldError>

                                <FieldError error={form.errors.description}>
                                    <textarea
                                        placeholder="Description (Optional)"
                                        value={form.data.description}
                                        onChange={(e) =>
                                            form.setData("description", e.target.value)
                                        }
                                        className="w-full rounded-md border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                        rows={3}
                                    />
                                </FieldError>

                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        id="is_active"
                                        checked={form.data.is_active}
                                        onChange={(e) =>
                                            form.setData("is_active", e.target.checked)
                                        }
                                        className="h-4 w-4 rounded border-slate-300"
                                    />
                                    <label htmlFor="is_active" className="text-sm font-medium">
                                        Active
                                    </label>
                                </div>

                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="w-full rounded-md bg-slate-900 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50 dark:bg-slate-50 dark:text-slate-900 dark:hover:bg-slate-200"
                                >
                                    {editing ? "Update Brand" : "Save Brand"}
                                </button>
                            </div>
                        </form>
                    </section>
                </div>
            </div>

            <DeleteConfirmDialog
                open={!!deletingBrand}
                onOpenChange={(open) => {
                    if (!open && !isDeleting) {
                        setDeletingBrand(null);
                    }
                }}
                onConfirm={confirmDelete}
                title="Delete Brand?"
                itemName={deletingBrand?.name}
                processing={isDeleting}
            />
        </ERPLayout>
    );
}