import AdminLayout from "@/Layouts/admin-layout";
import { Head, Link, useForm } from "@inertiajs/react";
import { FormEvent } from "react";
import toast from "react-hot-toast";

type Page = {
    id: number;
    title: string;
    slug: string;
    status: "draft" | "published";
    template: string;
    meta_title: string | null;
    meta_description: string | null;
    published_at: string | null;
};

type Props = {
    page: Page;
};

export default function Edit({ page }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        title: page.title,
        slug: page.slug,
        status: page.status,
        template: page.template,
        meta_title: page.meta_title ?? "",
        meta_description: page.meta_description ?? "",
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();

        put(`/admin/pages/${page.id}`, {
            onError: () => {
                toast.error(
                    "Please check the form and correct the errors.",
                );
            },
        });
    };

    return (
        <AdminLayout>
            <Head title={`Edit ${page.title}`} />

            <div className="mx-auto max-w-7xl">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900">
                            Edit Page
                        </h1>

                        <p className="mt-1 text-sm text-slate-600">
                            Update page information and SEO settings.
                        </p>
                    </div>

                    <Link
                        href="/admin/pages"
                        className="text-sm font-medium text-slate-600 hover:text-slate-900"
                    >
                        Back to Pages
                    </Link>
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
                >
                    <div>
                        <label className="mb-2 block text-sm font-medium text-slate-700">
                            Page Title
                        </label>

                        <input
                            type="text"
                            value={data.title}
                            onChange={(e) =>
                                setData("title", e.target.value)
                            }
                            className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-slate-500"
                        />

                        {errors.title && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.title}
                            </p>
                        )}
                    </div>

                    <div>
                        <label className="mb-2 block text-sm font-medium text-slate-700">
                            Slug
                        </label>

                        <input
                            type="text"
                            value={data.slug}
                            onChange={(e) =>
                                setData("slug", e.target.value)
                            }
                            className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-slate-500"
                        />

                        {errors.slug && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.slug}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-6 md:grid-cols-2">
                        <div>
                            <label className="mb-2 block text-sm font-medium text-slate-700">
                                Status
                            </label>

                            <select
                                value={data.status}
                                onChange={(e) =>
                                    setData(
                                        "status",
                                        e.target.value as "draft" | "published",
                                    )
                                }
                                className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-slate-500"
                            >
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                            </select>

                            {errors.status && (
                                <p className="mt-1 text-sm text-red-600">
                                    {errors.status}
                                </p>
                            )}
                        </div>

                        <div>
                            <label className="mb-2 block text-sm font-medium text-slate-700">
                                Template
                            </label>

                            <select
                                value={data.template}
                                onChange={(e) =>
                                    setData("template", e.target.value)
                                }
                                className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-slate-500"
                            >
                                <option value="default">
                                    Default
                                </option>

                                <option value="full-width">
                                    Full Width
                                </option>
                            </select>

                            {errors.template && (
                                <p className="mt-1 text-sm text-red-600">
                                    {errors.template}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="border-t border-slate-200 pt-6">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900">
                            SEO
                        </h2>

                        <div className="space-y-5">
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700">
                                    Meta Title
                                </label>

                                <input
                                    type="text"
                                    value={data.meta_title}
                                    onChange={(e) =>
                                        setData(
                                            "meta_title",
                                            e.target.value,
                                        )
                                    }
                                    className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-slate-500"
                                />

                                {errors.meta_title && (
                                    <p className="mt-1 text-sm text-red-600">
                                        {errors.meta_title}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700">
                                    Meta Description
                                </label>

                                <textarea
                                    rows={4}
                                    value={data.meta_description}
                                    onChange={(e) =>
                                        setData(
                                            "meta_description",
                                            e.target.value,
                                        )
                                    }
                                    className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-slate-500"
                                />

                                {errors.meta_description && (
                                    <p className="mt-1 text-sm text-red-600">
                                        {errors.meta_description}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex justify-end gap-3 border-t border-slate-200 pt-6">
                        <Link
                            href="/admin/pages"
                            className="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Cancel
                        </Link>

                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {processing ? "Saving..." : "Save Changes"}
                        </button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}