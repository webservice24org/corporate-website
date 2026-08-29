import AdminLayout from "@/Layouts/admin-layout";
import { Head, Link, useForm } from "@inertiajs/react";
import {
    FormEvent,
    ReactNode,
    useState,
} from "react";
import toast from "react-hot-toast";

type PageTemplate =
    | "standard"
    | "builder";

type PageLayout =
    | "full-width"
    | "left-sidebar"
    | "right-sidebar";

type Page = {
    id: number;

    title: string;
    slug: string;

    description:
        | string
        | null;

    featured_image:
        | string
        | null;

    layout:
        | PageLayout
        | null;

    status:
        | "draft"
        | "published";

    template: string;

    is_home?: boolean;

    meta_title:
        | string
        | null;

    meta_description:
        | string
        | null;

    published_at:
        | string
        | null;
};

type Props = {
    page: Page;
};

type PageForm = {
    _method: "put";

    title: string;
    slug: string;

    description: string;

    featured_image:
        | File
        | null;

    remove_featured_image:
        boolean;

    status:
        | "draft"
        | "published";

    template: PageTemplate;

    layout: PageLayout;

    is_home: boolean;

    meta_title: string;
    meta_description: string;
};

export default function Edit({
    page,
}: Props) {
    /*
     * Backward compatibility:
     *
     * Old pages may still have
     * template = default or full-width.
     *
     * Until we migrate those old values,
     * consider anything other than
     * "builder" a Standard Page.
     */
    const initialTemplate: PageTemplate =
        page.template ===
        "builder"
            ? "builder"
            : "standard";

    const initialLayout: PageLayout =
        page.layout ===
            "left-sidebar" ||
        page.layout ===
            "right-sidebar" ||
        page.layout ===
            "full-width"
            ? page.layout
            : "full-width";

    const [
        newImagePreview,
        setNewImagePreview,
    ] = useState<
        string | null
    >(null);

    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm<PageForm>({
        _method: "put",

        title:
            page.title,

        slug:
            page.slug,

        description:
            page.description ??
            "",

        featured_image:
            null,

        remove_featured_image:
            false,

        status:
            page.status,

        template:
            initialTemplate,

        layout:
            initialLayout,

        is_home:
            page.is_home ??
            false,

        meta_title:
            page.meta_title ??
            "",

        meta_description:
            page.meta_description ??
            "",
    });

    const handleFeaturedImage = (
        file: File | null,
    ) => {
        setData(
            "featured_image",
            file,
        );

        if (
            newImagePreview
        ) {
            URL.revokeObjectURL(
                newImagePreview,
            );
        }

        if (!file) {
            setNewImagePreview(
                null,
            );

            return;
        }

        setNewImagePreview(
            URL.createObjectURL(
                file,
            ),
        );

        /*
         * Selecting a new image means
         * we no longer want to simply
         * remove the image.
         */
        setData(
            "remove_featured_image",
            false,
        );
    };

    const removeFeaturedImage =
        () => {
            setData(
                "featured_image",
                null,
            );

            setData(
                "remove_featured_image",
                true,
            );

            if (
                newImagePreview
            ) {
                URL.revokeObjectURL(
                    newImagePreview,
                );
            }

            setNewImagePreview(
                null,
            );
        };

    const submit = (
        e: FormEvent,
    ) => {
        e.preventDefault();

        post(
            `/admin/pages/${page.id}`,
            {
                forceFormData:
                    true,

                onError:
                    () => {
                        toast.error(
                            "Please check the form and correct the errors.",
                        );
                    },
            },
        );
    };

    const showExistingImage =
        page.featured_image &&
        !data.remove_featured_image &&
        !newImagePreview;

    return (
        <AdminLayout>
            <Head
                title={`Edit ${page.title}`}
            />

            <div className="mx-auto max-w-7xl">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900">
                            Edit Page
                        </h1>

                        <p className="mt-1 text-sm text-slate-600">
                            Update page content and settings.
                        </p>
                    </div>

                    <Link
                        href="/admin/pages"
                        className="text-sm font-medium text-slate-600 hover:text-slate-900"
                    >
                        Back to Pages
                    </Link>
                </div>

                <form onSubmit={submit}>
                    <div className="grid gap-6 lg:grid-cols-12">
                        {/* LEFT */}
                        <div className="space-y-6 lg:col-span-8">
                            <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                                <div className="space-y-6">
                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700">
                                            Page Title
                                        </label>

                                        <input
                                            type="text"
                                            value={
                                                data.title
                                            }
                                            onChange={(
                                                e,
                                            ) =>
                                                setData(
                                                    "title",
                                                    e
                                                        .target
                                                        .value,
                                                )
                                            }
                                            className="w-full rounded-md border border-slate-300 px-4 py-3 text-lg font-medium outline-none focus:border-slate-500"
                                        />

                                        {errors.title && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {
                                                    errors.title
                                                }
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700">
                                            Slug
                                        </label>

                                        <input
                                            type="text"
                                            value={
                                                data.slug
                                            }
                                            onChange={(
                                                e,
                                            ) =>
                                                setData(
                                                    "slug",
                                                    e
                                                        .target
                                                        .value,
                                                )
                                            }
                                            className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-slate-500"
                                        />

                                        {errors.slug && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {
                                                    errors.slug
                                                }
                                            </p>
                                        )}
                                    </div>

                                    {data.template ===
                                        "standard" && (
                                        <div>
                                            <label className="mb-2 block text-sm font-medium text-slate-700">
                                                Description
                                            </label>

                                            <textarea
                                                rows={
                                                    18
                                                }
                                                value={
                                                    data.description
                                                }
                                                onChange={(
                                                    e,
                                                ) =>
                                                    setData(
                                                        "description",
                                                        e
                                                            .target
                                                            .value,
                                                    )
                                                }
                                                className="w-full rounded-md border border-slate-300 px-4 py-3 leading-7 outline-none focus:border-slate-500"
                                            />

                                            {errors.description && (
                                                <p className="mt-1 text-sm text-red-600">
                                                    {
                                                        errors.description
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    )}

                                    {data.template ===
                                        "builder" && (
                                        <div className="rounded-lg border border-blue-200 bg-blue-50 p-5">
                                            <h3 className="font-semibold text-blue-900">
                                                Page Builder
                                            </h3>

                                            <p className="mt-1 text-sm leading-6 text-blue-700">
                                                This page uses the Page Builder. Save any changes here and manage its sections from the Pages screen.
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* SEO */}
                            <details className="group overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <summary className="flex cursor-pointer list-none items-center justify-between px-6 py-4">
                                    <div>
                                        <h2 className="font-semibold text-slate-900">
                                            SEO
                                        </h2>

                                        <p className="mt-1 text-xs text-slate-500">
                                            Search engine title and description
                                        </p>
                                    </div>

                                    <span className="text-lg text-slate-400 transition-transform group-open:rotate-180">
                                        ▾
                                    </span>
                                </summary>

                                <div className="space-y-5 border-t border-slate-200 px-6 py-5">
                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700">
                                            Meta Title
                                        </label>

                                        <input
                                            type="text"
                                            value={
                                                data.meta_title
                                            }
                                            onChange={(
                                                e,
                                            ) =>
                                                setData(
                                                    "meta_title",
                                                    e
                                                        .target
                                                        .value,
                                                )
                                            }
                                            className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-slate-500"
                                        />

                                        {errors.meta_title && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {
                                                    errors.meta_title
                                                }
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700">
                                            Meta Description
                                        </label>

                                        <textarea
                                            rows={
                                                4
                                            }
                                            value={
                                                data.meta_description
                                            }
                                            onChange={(
                                                e,
                                            ) =>
                                                setData(
                                                    "meta_description",
                                                    e
                                                        .target
                                                        .value,
                                                )
                                            }
                                            className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-slate-500"
                                        />

                                        {errors.meta_description && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {
                                                    errors.meta_description
                                                }
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </details>
                        </div>

                        {/* RIGHT */}
                        <div className="space-y-6 lg:col-span-4">
                            {/* Publish */}
                            <SidebarCard
                                title="Publish"
                            >
                                <div className="space-y-5">
                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700">
                                            Status
                                        </label>

                                        <select
                                            value={
                                                data.status
                                            }
                                            onChange={(
                                                e,
                                            ) =>
                                                setData(
                                                    "status",
                                                    e
                                                        .target
                                                        .value as
                                                        | "draft"
                                                        | "published",
                                                )
                                            }
                                            className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-slate-500"
                                        >
                                            <option value="draft">
                                                Draft
                                            </option>

                                            <option value="published">
                                                Published
                                            </option>
                                        </select>
                                    </div>

                                    <label className="flex items-center gap-3">
                                        <input
                                            type="checkbox"
                                            checked={
                                                data.is_home
                                            }
                                            onChange={(
                                                e,
                                            ) =>
                                                setData(
                                                    "is_home",
                                                    e
                                                        .target
                                                        .checked,
                                                )
                                            }
                                            className="size-4 rounded border-slate-300"
                                        />

                                        <span className="text-sm font-medium text-slate-700">
                                            Use as Homepage
                                        </span>
                                    </label>
                                </div>

                                <div className="mt-6 flex items-center justify-between border-t border-slate-200 pt-4">
                                    <Link
                                        href="/admin/pages"
                                        className="text-sm text-slate-500 hover:text-slate-900"
                                    >
                                        Cancel
                                    </Link>

                                    <button
                                        type="submit"
                                        disabled={
                                            processing
                                        }
                                        className="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                                    >
                                        {processing
                                            ? "Saving..."
                                            : "Update"}
                                    </button>
                                </div>
                            </SidebarCard>

                            {/* Page Type */}
                            <SidebarCard
                                title="Page Type"
                            >
                                <div className="space-y-3">
                                    <RadioOption
                                        name="template"
                                        title="Standard Page"
                                        description="Use normal page content."
                                        checked={
                                            data.template ===
                                            "standard"
                                        }
                                        onChange={() =>
                                            setData(
                                                "template",
                                                "standard",
                                            )
                                        }
                                    />

                                    <RadioOption
                                        name="template"
                                        title="Page Builder"
                                        description="Build with sections."
                                        checked={
                                            data.template ===
                                            "builder"
                                        }
                                        onChange={() =>
                                            setData(
                                                "template",
                                                "builder",
                                            )
                                        }
                                    />
                                </div>
                            </SidebarCard>

                            {/* Layout */}
                            <SidebarCard
                                title="Page Layout"
                            >
                                <div className="space-y-3">
                                    <RadioOption
                                        name="layout"
                                        title="Full Width"
                                        checked={
                                            data.layout ===
                                            "full-width"
                                        }
                                        onChange={() =>
                                            setData(
                                                "layout",
                                                "full-width",
                                            )
                                        }
                                    />

                                    <RadioOption
                                        name="layout"
                                        title="Left Sidebar"
                                        checked={
                                            data.layout ===
                                            "left-sidebar"
                                        }
                                        onChange={() =>
                                            setData(
                                                "layout",
                                                "left-sidebar",
                                            )
                                        }
                                    />

                                    <RadioOption
                                        name="layout"
                                        title="Right Sidebar"
                                        checked={
                                            data.layout ===
                                            "right-sidebar"
                                        }
                                        onChange={() =>
                                            setData(
                                                "layout",
                                                "right-sidebar",
                                            )
                                        }
                                    />
                                </div>
                            </SidebarCard>

                            {/* Featured Image */}
                            <SidebarCard
                                title="Featured Image"
                            >
                                {showExistingImage && (
                                    <div className="mb-4">
                                        <div className="overflow-hidden rounded-md border border-slate-200">
                                            <img
                                                src={`/storage/${page.featured_image}`}
                                                alt={
                                                    page.title
                                                }
                                                className="aspect-video w-full object-cover"
                                            />
                                        </div>

                                        <button
                                            type="button"
                                            onClick={
                                                removeFeaturedImage
                                            }
                                            className="mt-2 text-sm font-medium text-red-600 hover:text-red-700"
                                        >
                                            Remove featured image
                                        </button>
                                    </div>
                                )}

                                {newImagePreview && (
                                    <div className="mb-4">
                                        <div className="overflow-hidden rounded-md border border-slate-200">
                                            <img
                                                src={
                                                    newImagePreview
                                                }
                                                alt="New featured image preview"
                                                className="aspect-video w-full object-cover"
                                            />
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() =>
                                                handleFeaturedImage(
                                                    null,
                                                )
                                            }
                                            className="mt-2 text-sm font-medium text-red-600 hover:text-red-700"
                                        >
                                            Cancel new image
                                        </button>
                                    </div>
                                )}

                                {data.remove_featured_image &&
                                    !newImagePreview && (
                                        <div className="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3">
                                            <p className="text-sm text-amber-800">
                                                Featured image will be removed when the page is updated.
                                            </p>

                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setData(
                                                        "remove_featured_image",
                                                        false,
                                                    )
                                                }
                                                className="mt-2 text-sm font-medium text-amber-900 underline"
                                            >
                                                Undo
                                            </button>
                                        </div>
                                    )}

                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    onChange={(
                                        e,
                                    ) =>
                                        handleFeaturedImage(
                                            e
                                                .target
                                                .files?.[0] ??
                                                null,
                                        )
                                    }
                                    className="block w-full text-sm text-slate-600"
                                />

                                {errors.featured_image && (
                                    <p className="mt-2 text-sm text-red-600">
                                        {
                                            errors.featured_image
                                        }
                                    </p>
                                )}
                            </SidebarCard>
                        </div>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}

type LayoutOptionProps = {
    title: string;
    description: string;
    value: PageLayout;
    selected: boolean;
    onSelect: () => void;
};

function SidebarCard({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-200 px-5 py-3">
                <h2 className="font-semibold text-slate-900">
                    {title}
                </h2>
            </div>

            <div className="p-5">
                {children}
            </div>
        </div>
    );
}

function RadioOption({
    name,
    title,
    description,
    checked,
    onChange,
}: {
    name: string;
    title: string;
    description?: string;
    checked: boolean;
    onChange: () => void;
}) {
    return (
        <label
            className={`flex cursor-pointer gap-3 rounded-md border p-3 transition ${
                checked
                    ? "border-slate-900 bg-slate-50"
                    : "border-slate-200 hover:border-slate-300"
            }`}
        >
            <input
                type="radio"
                name={name}
                checked={checked}
                onChange={onChange}
                className="mt-1"
            />

            <div>
                <div className="text-sm font-medium text-slate-900">
                    {title}
                </div>

                {description && (
                    <p className="mt-0.5 text-xs leading-5 text-slate-500">
                        {description}
                    </p>
                )}
            </div>
        </label>
    );
}