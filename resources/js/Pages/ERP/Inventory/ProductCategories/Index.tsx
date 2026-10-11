import { Head, router, useForm } from "@inertiajs/react";
import { Pencil, Plus, Search, Trash2, X } from "lucide-react";
import { useState } from "react";
import type { FormEvent, ReactNode } from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";

type Company = { id: number; name: string };
type ParentCategory = { id: number; company_id: number; name: string };
type PaginationLink = { url: string | null; label: string; active: boolean };

type ProductCategory = {
    id: number;
    company_id: number;
    parent_id: number | null;
    name: string;
    code: string | null;
    description: string | null;
    sort_order: number;
    is_active: boolean;
    company?: { id: number; name: string } | null;
    parent?: { id: number; name: string } | null;
};

type CategoryFormData = {
    company_id: number | "";
    parent_id: number | "";
    name: string;
    code: string;
    description: string;
    sort_order: number;
    is_active: boolean;
};

interface Props {
    categories: {
        data: ProductCategory[];
        links: PaginationLink[];
        total?: number;
    };
    companies: Company[];
    parents: ParentCategory[];
    filters?: {
        search?: string;
        company_id?: string | number;
        status?: string;
    };
}

export default function Index({ categories, companies, parents, filters }: Props) {
    const { can } = useAuthorization();

    const [editingCategory, setEditingCategory] = useState<ProductCategory | null>(null);
    const [deletingCategory, setDeletingCategory] = useState<ProductCategory | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const [search, setSearch] = useState(filters?.search ?? "");
    const [companyFilter, setCompanyFilter] = useState(
        filters?.company_id !== undefined && filters?.company_id !== null
            ? String(filters.company_id)
            : ""
    );
    const [statusFilter, setStatusFilter] = useState(filters?.status ?? "");

    const form = useForm<CategoryFormData>({
        company_id: "",
        parent_id: "",
        name: "",
        code: "",
        description: "",
        sort_order: 0,
        is_active: true,
    });

    const availableParents = parents.filter(
        (parent) =>
            parent.company_id === Number(form.data.company_id || 0) &&
            parent.id !== editingCategory?.id
    );

    const resetForm = () => {
        setEditingCategory(null);
        form.reset();
        form.clearErrors();
    };

    const startEdit = (category: ProductCategory) => {
        setEditingCategory(category);
        form.clearErrors();
        form.setData({
            company_id: category.company_id,
            parent_id: category.parent_id ?? "",
            name: category.name,
            code: category.code ?? "",
            description: category.description ?? "",
            sort_order: category.sort_order,
            is_active: category.is_active,
        });
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (editingCategory) {
            form.put(`/erp/inventory/product-categories/${editingCategory.id}`, {
                preserveScroll: true,
                onSuccess: resetForm,
            });
            return;
        }

        form.post("/erp/inventory/product-categories", {
            preserveScroll: true,
            onSuccess: resetForm,
        });
    };

    const confirmDelete = () => {
        if (!deletingCategory) return;

        setIsDeleting(true);
        router.delete(`/erp/inventory/product-categories/${deletingCategory.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setIsDeleting(false);
                setDeletingCategory(null);
            },
        });
    };

    const applyFilters = () => {
        router.get(
            "/erp/inventory/product-categories",
            {
                search: search || undefined,
                company_id: companyFilter || undefined,
                status: statusFilter || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    };

    const resetFilters = () => {
        setSearch("");
        setCompanyFilter("");
        setStatusFilter("");
        router.get(
            "/erp/inventory/product-categories",
            {},
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    return (
        <ERPLayout>
            <Head title="Product Categories" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">Product Categories</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Manage company-specific product categories and hierarchy.
                    </p>
                </div>

                <div className="grid min-w-0 gap-6 2xl:grid-cols-[minmax(0,1fr)_380px]">
                  <section className="min-w-0 space-y-4">
                      <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_220px_160px_auto_auto]">
                              <div className="relative">
                                  <Search className="absolute left-3 top-3 h-4 w-4 text-slate-400" />
                                  <input
                                      value={search}
                                      onChange={(e) => setSearch(e.target.value)}
                                      onKeyDown={(e) => e.key === "Enter" && applyFilters()}
                                      placeholder="Search name, code or description"
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

                              <select
                                  value={statusFilter}
                                  onChange={(e) => setStatusFilter(e.target.value)}
                                  className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                              >
                                  <option value="">All Status</option>
                                  <option value="active">Active</option>
                                  <option value="inactive">Inactive</option>
                              </select>

                              <button type="button" onClick={applyFilters} className="h-10 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950">
                                  Apply
                              </button>
                              <button type="button" onClick={resetFilters} className="h-10 rounded-md border border-slate-200 px-4 text-sm font-medium dark:border-slate-800">
                                  Reset
                              </button>
                          </div>
                      </div>

                      <div className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                          <div className="w-full overflow-x-auto">
                              <table className="w-full min-w-[760px] text-left text-sm">
                                  <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                                      <tr>
                                          <th className="px-5 py-3">Category</th>
                                          <th className="px-5 py-3">Parent</th>
                                          <th className="px-5 py-3">Code</th>
                                          <th className="px-5 py-3">Company</th>
                                          <th className="px-5 py-3">Sort</th>
                                          <th className="px-5 py-3">Status</th>
                                          <th className="px-5 py-3 text-right">Actions</th>
                                      </tr>
                                  </thead>
                                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                      {categories.data.map((category) => (
                                          <tr key={category.id}>
                                              <td className="px-5 py-4 font-medium">{category.name}</td>
                                              <td className="px-5 py-4">{category.parent?.name ?? "—"}</td>
                                              <td className="px-5 py-4">{category.code ?? "—"}</td>
                                              <td className="px-5 py-4">{category.company?.name ?? "—"}</td>
                                              <td className="px-5 py-4">{category.sort_order}</td>
                                              <td className="px-5 py-4">{category.is_active ? "Active" : "Inactive"}</td>
                                              <td className="px-5 py-4">
                                                  <div className="flex justify-end gap-2">
                                                      {can("erp.product-categories.edit") && (
                                                          <button
                                                              type="button"
                                                              title="Edit Category"
                                                              onClick={() => startEdit(category)}
                                                              className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                          >
                                                              <Pencil className="h-4 w-4" />
                                                          </button>
                                                      )}

                                                      {can("erp.product-categories.delete") && (
                                                          <button
                                                              type="button"
                                                              title="Delete Category"
                                                              onClick={() => setDeletingCategory(category)}
                                                              className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950/30"
                                                          >
                                                              <Trash2 className="h-4 w-4" />
                                                          </button>
                                                      )}
                                                  </div>
                                              </td>
                                          </tr>
                                      ))}

                                      {categories.data.length === 0 && (
                                          <tr>
                                              <td colSpan={7} className="px-5 py-12 text-center text-slate-500">
                                                  No product categories found.
                                              </td>
                                          </tr>
                                      )}
                                  </tbody>
                              </table>
                          </div>
                      </div>

                      {categories.links?.length > 3 && (
                          <div className="flex flex-wrap gap-2">
                              {categories.links.map((link, index) => (
                                  <button
                                      key={`${link.label}-${index}`}
                                      type="button"
                                      disabled={!link.url}
                                      onClick={() => link.url && router.visit(link.url, { preserveScroll: true })}
                                      className={`h-9 rounded-md border px-3 text-sm ${
                                          link.active
                                              ? "bg-slate-900 text-white dark:bg-white dark:text-slate-950"
                                              : "border-slate-200 dark:border-slate-800"
                                      } disabled:opacity-40`}
                                      dangerouslySetInnerHTML={{ __html: link.label }}
                                  />
                              ))}
                          </div>
                      )}
                  </section>

                  <section className="min-w-0 h-fit rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950 2xl:sticky 2xl:top-6">
                      <form onSubmit={submit}>
                          <div className="flex items-center justify-between">
                              <div className="flex items-center gap-2">
                                  <Plus className="h-4 w-4" />
                                  <h2 className="font-semibold">{editingCategory ? "Edit Product Category" : "Add Product Category"}</h2>
                              </div>
                              {editingCategory && (
                                  <button type="button" onClick={resetForm} title="Cancel Edit" className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800">
                                      <X className="h-4 w-4" />
                                  </button>
                              )}
                          </div>

                          <div className="mt-5 space-y-4">
                              <FieldError error={form.errors.company_id}>
                                  <select
                                      value={form.data.company_id}
                                      onChange={(e) => {
                                          form.setData("company_id", e.target.value ? Number(e.target.value) : "");
                                          form.setData("parent_id", "");
                                      }}
                                      className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                  >
                                      <option value="">Select Company</option>
                                      {companies.map((company) => (
                                          <option key={company.id} value={company.id}>{company.name}</option>
                                      ))}
                                  </select>
                              </FieldError>

                              <FieldError error={form.errors.parent_id}>
                                  <select
                                      value={form.data.parent_id}
                                      onChange={(e) => form.setData("parent_id", e.target.value ? Number(e.target.value) : "")}
                                      disabled={!form.data.company_id}
                                      className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm disabled:opacity-50 dark:border-slate-800 dark:bg-slate-950"
                                  >
                                      <option value="">No Parent Category</option>
                                      {availableParents.map((parent) => (
                                          <option key={parent.id} value={parent.id}>{parent.name}</option>
                                      ))}
                                  </select>
                              </FieldError>

                              <FieldError error={form.errors.name}>
                                  <input value={form.data.name} onChange={(e) => form.setData("name", e.target.value)} placeholder="Category Name" className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950" />
                              </FieldError>

                              <FieldError error={form.errors.code}>
                                  <input value={form.data.code} onChange={(e) => form.setData("code", e.target.value)} placeholder="Category Code (Optional)" className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950" />
                              </FieldError>

                              <FieldError error={form.errors.sort_order}>
                                  <input type="number" min="0" value={form.data.sort_order} onChange={(e) => form.setData("sort_order", Number(e.target.value))} placeholder="Sort Order" className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950" />
                              </FieldError>

                              <FieldError error={form.errors.description}>
                                  <textarea rows={4} value={form.data.description} onChange={(e) => form.setData("description", e.target.value)} placeholder="Description (Optional)" className="w-full rounded-md border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-950" />
                              </FieldError>

                              <label className="flex items-center gap-2 text-sm font-medium">
                                  <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData("is_active", e.target.checked)} className="h-4 w-4 rounded border-slate-300" />
                                  Active
                              </label>

                              {(editingCategory ? can("erp.product-categories.edit") : can("erp.product-categories.create")) && (
                                  <button type="submit" disabled={form.processing || !form.data.company_id || !form.data.name} className="w-full rounded-md bg-slate-900 py-2.5 text-sm font-medium text-white disabled:opacity-50 dark:bg-slate-50 dark:text-slate-900">
                                      {form.processing ? "Saving..." : editingCategory ? "Update Category" : "Create Category"}
                                  </button>
                              )}
                          </div>
                      </form>
                  </section>
                </div>
            </div>

            <DeleteConfirmDialog
                open={!!deletingCategory}
                onOpenChange={(open) => {
                    if (!open && !isDeleting) {
                        setDeletingCategory(null);
                    }
                }}
                onConfirm={confirmDelete}
                title="Delete Product Category?"
                itemName={deletingCategory?.name}
                processing={isDeleting}
            />
        </ERPLayout>
    );
}

function FieldError({ error, children }: { error?: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            {children}
            {error && <p className="text-xs text-red-500">{error}</p>}
        </div>
    );
}
