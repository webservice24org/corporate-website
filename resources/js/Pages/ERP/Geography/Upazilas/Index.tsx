import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Edit3,
    MapPinned,
    Plus,
    Search,
    Trash2,
} from "lucide-react";

import {
    useMemo,
    useState,
} from "react";

import type {
    FormEvent,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import type {
    District,
    Division,
    Paginated,
    Upazila,
} from "@/types/geography";

type Props = {
    upazilas:
        Paginated<Upazila>;

    divisions:
        Division[];

    districts:
        District[];

    filters: {
        search?:
            string | null;

        division_id?:
            number | string | null;

        district_id?:
            number | string | null;

        status?:
            string | null;
    };
};

export default function Index({
    upazilas,
    divisions,
    districts,
    filters,
}: Props) {
    const {
        can,
    } =
        useAuthorization();

    const [
        search,
        setSearch,
    ] =
        useState(
            filters.search ??
                ""
        );

    const [
        divisionId,
        setDivisionId,
    ] =
        useState(
            filters.division_id
                ? String(
                      filters.division_id
                  )
                : ""
        );

    const [
        districtId,
        setDistrictId,
    ] =
        useState(
            filters.district_id
                ? String(
                      filters.district_id
                  )
                : ""
        );

    const [
        status,
        setStatus,
    ] =
        useState(
            filters.status ??
                ""
        );

    const [
        deleting,
        setDeleting,
    ] =
        useState<Upazila | null>(
            null
        );

    const filteredDistricts =
        useMemo(
            () => {
                if (
                    !divisionId
                ) {
                    return districts;
                }

                return districts.filter(
                    (district) =>
                        district.division_id ===
                        Number(
                            divisionId
                        )
                );
            },
            [
                districts,
                divisionId,
            ]
        );

    const changeDivision = (
        value: string
    ) => {
        setDivisionId(
            value
        );

        if (
            districtId
        ) {
            const selectedDistrict =
                districts.find(
                    (district) =>
                        district.id ===
                        Number(
                            districtId
                        )
                );

            if (
                !selectedDistrict ||
                (
                    value &&
                    selectedDistrict.division_id !==
                        Number(
                            value
                        )
                )
            ) {
                setDistrictId(
                    ""
                );
            }
        }
    };

    const submitFilter = (
        event:
            FormEvent
    ) => {
        event.preventDefault();

        router.get(
            "/erp/geography/upazilas",
            {
                search:
                    search ||
                    undefined,

                division_id:
                    divisionId ||
                    undefined,

                district_id:
                    districtId ||
                    undefined,

                status:
                    status ||
                    undefined,
            },
            {
                preserveState:
                    true,

                preserveScroll:
                    true,

                replace:
                    true,
            }
        );
    };

    const clearFilters =
        () => {
            setSearch("");
            setDivisionId("");
            setDistrictId("");
            setStatus("");

            router.get(
                "/erp/geography/upazilas",
                {},
                {
                    preserveState:
                        true,

                    preserveScroll:
                        true,

                    replace:
                        true,
                }
            );
        };

    const deleteUpazila =
        () => {
            if (!deleting) {
                return;
            }

            router.delete(
                `/erp/geography/upazilas/${deleting.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeleting(
                                null
                            ),
                }
            );
        };

    return (
        <ERPLayout>
            <Head title="Upazilas" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Upazilas
                        </h1>

                        <p className="mt-1 text-sm text-neutral-500">
                            Manage Upazilas
                            under their
                            District and
                            Division hierarchy.
                        </p>
                    </div>

                    {can(
                        "erp.upazilas.create"
                    ) && (
                        <Link
                            href="/erp/geography/upazilas/create"
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white dark:bg-white dark:text-neutral-950"
                        >
                            <Plus className="h-4 w-4" />

                            Add Upazila
                        </Link>
                    )}
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-950">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-neutral-500">
                                    Total Upazilas
                                </p>

                                <p className="mt-2 text-2xl font-semibold">
                                    {
                                        upazilas.total
                                    }
                                </p>
                            </div>

                            <MapPinned className="h-5 w-5 text-neutral-400" />
                        </div>
                    </div>
                </div>

                <form
                    onSubmit={
                        submitFilter
                    }
                    className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950"
                >
                    <div className="grid gap-3 xl:grid-cols-[1fr_190px_220px_160px_auto_auto]">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />

                            <input
                                value={
                                    search
                                }
                                onChange={(
                                    event
                                ) =>
                                    setSearch(
                                        event.target.value
                                    )
                                }
                                placeholder="Search Upazila name, Bengali name or code..."
                                className="h-10 w-full rounded-md border border-neutral-200 bg-white pl-9 pr-3 text-sm outline-none dark:border-neutral-800 dark:bg-neutral-950"
                            />
                        </div>

                        <select
                            value={
                                divisionId
                            }
                            onChange={(
                                event
                            ) =>
                                changeDivision(
                                    event.target.value
                                )
                            }
                            className="h-10 rounded-md border border-neutral-200 bg-white px-3 text-sm outline-none dark:border-neutral-800 dark:bg-neutral-950"
                        >
                            <option value="">
                                All Divisions
                            </option>

                            {divisions.map(
                                (
                                    division
                                ) => (
                                    <option
                                        key={
                                            division.id
                                        }
                                        value={
                                            division.id
                                        }
                                    >
                                        {
                                            division.name
                                        }
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={
                                districtId
                            }
                            onChange={(
                                event
                            ) =>
                                setDistrictId(
                                    event.target.value
                                )
                            }
                            className="h-10 rounded-md border border-neutral-200 bg-white px-3 text-sm outline-none dark:border-neutral-800 dark:bg-neutral-950"
                        >
                            <option value="">
                                All Districts
                            </option>

                            {filteredDistricts.map(
                                (
                                    district
                                ) => (
                                    <option
                                        key={
                                            district.id
                                        }
                                        value={
                                            district.id
                                        }
                                    >
                                        {
                                            district.name
                                        }
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={
                                status
                            }
                            onChange={(
                                event
                            ) =>
                                setStatus(
                                    event.target.value
                                )
                            }
                            className="h-10 rounded-md border border-neutral-200 bg-white px-3 text-sm outline-none dark:border-neutral-800 dark:bg-neutral-950"
                        >
                            <option value="">
                                All Statuses
                            </option>

                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>

                        <button
                            type="submit"
                            className="h-10 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white dark:bg-white dark:text-neutral-950"
                        >
                            Filter
                        </button>

                        <button
                            type="button"
                            onClick={
                                clearFilters
                            }
                            className="h-10 rounded-md border border-neutral-200 px-4 text-sm font-medium dark:border-neutral-800"
                        >
                            Clear
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-950">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b border-neutral-200 bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900/40">
                                <tr>
                                    <th className="px-4 py-3">
                                        Upazila
                                    </th>

                                    <th className="px-4 py-3">
                                        Bengali
                                    </th>

                                    <th className="px-4 py-3">
                                        District
                                    </th>

                                    <th className="px-4 py-3">
                                        Division
                                    </th>

                                    <th className="px-4 py-3">
                                        Code
                                    </th>

                                    <th className="px-4 py-3">
                                        Status
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                {upazilas.data.map(
                                    (
                                        upazila
                                    ) => (
                                        <tr
                                            key={
                                                upazila.id
                                            }
                                            className="hover:bg-neutral-50 dark:hover:bg-neutral-900/40"
                                        >
                                            <td className="px-4 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-900">
                                                        <MapPinned className="h-4 w-4" />
                                                    </div>

                                                    <span className="font-medium">
                                                        {
                                                            upazila.name
                                                        }
                                                    </span>
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    upazila.name_bn ??
                                                    "—"
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    upazila.district
                                                        ?.name ??
                                                    "—"
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    upazila.district
                                                        ?.division
                                                        ?.name ??
                                                    "—"
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                {upazila.code ? (
                                                    <span className="rounded-md bg-neutral-100 px-2 py-1 text-xs font-medium dark:bg-neutral-900">
                                                        {
                                                            upazila.code
                                                        }
                                                    </span>
                                                ) : (
                                                    "—"
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                                        upazila.is_active
                                                            ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                                                            : "bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                                    }`}
                                                >
                                                    {upazila.is_active
                                                        ? "Active"
                                                        : "Inactive"}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.upazilas.edit"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/geography/upazilas/${upazila.id}/edit`}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-neutral-200 transition hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900"
                                                            title="Edit Upazila"
                                                        >
                                                            <Edit3 className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.upazilas.delete"
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDeleting(
                                                                    upazila
                                                                )
                                                            }
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-600 transition hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950/30"
                                                            title="Delete Upazila"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {upazilas.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-6 py-14 text-center text-neutral-500"
                                        >
                                            No Upazilas
                                            found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {upazilas.links.length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-neutral-200 p-4 dark:border-neutral-800">
                            {upazilas.links.map(
                                (
                                    link,
                                    index
                                ) =>
                                    link.url ? (
                                        <Link
                                            key={
                                                index
                                            }
                                            href={
                                                link.url
                                            }
                                            preserveScroll
                                            className={`rounded-md px-3 py-2 text-sm ${
                                                link.active
                                                    ? "bg-neutral-950 text-white dark:bg-white dark:text-neutral-950"
                                                    : "border border-neutral-200 dark:border-neutral-800"
                                            }`}
                                            dangerouslySetInnerHTML={{
                                                __html:
                                                    link.label,
                                            }}
                                        />
                                    ) : (
                                        <span
                                            key={
                                                index
                                            }
                                            className="rounded-md border border-neutral-200 px-3 py-2 text-sm opacity-40 dark:border-neutral-800"
                                            dangerouslySetInnerHTML={{
                                                __html:
                                                    link.label,
                                            }}
                                        />
                                    )
                            )}
                        </div>
                    )}
                </div>
            </div>

            {deleting && (
                <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white shadow-2xl dark:bg-neutral-950">
                        <div className="p-6">
                            <div className="flex h-11 w-11 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/40">
                                <Trash2 className="h-5 w-5" />
                            </div>

                            <h2 className="mt-4 text-lg font-semibold">
                                Delete Upazila?
                            </h2>

                            <p className="mt-2 text-sm leading-6 text-neutral-500">
                                You are about to
                                delete{" "}
                                <strong className="text-neutral-900 dark:text-white">
                                    {
                                        deleting.name
                                    }
                                </strong>
                                . Once Dealer
                                Management is added,
                                referenced Upazilas
                                will be protected from
                                deletion.
                            </p>

                            <div className="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setDeleting(
                                            null
                                        )
                                    }
                                    className="h-10 rounded-md border border-neutral-200 px-4 text-sm font-medium dark:border-neutral-800"
                                >
                                    Cancel
                                </button>

                                <button
                                    type="button"
                                    onClick={
                                        deleteUpazila
                                    }
                                    className="h-10 rounded-md bg-red-600 px-4 text-sm font-medium text-white"
                                >
                                    Delete Upazila
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </ERPLayout>
    );
}