import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";
import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Clock3,
    Pencil,
    Plus,
    Search,
    Trash2,
} from "lucide-react";

import {
    useState,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

type Shift = {
    id: number;

    company_id: number;

    name: string;
    code: string;

    start_time: string;
    end_time: string;

    crosses_midnight: boolean;

    working_days:
        number[];

    status: string;

    company: Company;
};

type PaginationLink = {
    url:
        | string
        | null;

    label: string;

    active: boolean;
};

type PaginatedShifts = {
    data: Shift[];

    links:
        PaginationLink[];
};

type Filters = {
    search:
        | string
        | null;

    company_id:
        | string
        | null;

    status:
        | string
        | null;
};

type Props = {
    shifts:
        PaginatedShifts;

    companies:
        Company[];

    filters:
        Filters;
};

const dayLabels: Record<
    number,
    string
> = {
    0: "Sun",
    1: "Mon",
    2: "Tue",
    3: "Wed",
    4: "Thu",
    5: "Fri",
    6: "Sat",
};

function time(
    value: string,
): string {
    return value.substring(
        0,
        5,
    );
}

export default function Index({
    shifts,
    companies,
    filters,
}: Props) {
    const {
        can,
    } = useAuthorization();

    const [
        search,
        setSearch,
    ] = useState(
        filters.search ??
            "",
    );

    const [
        deleteShift,
        setDeleteShift,
    ] =
        useState<Shift | null>(
            null,
        );

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const filter = (
        values: Record<
            string,
            string
        >,
    ) => {
        router.get(
            "/erp/attendance/shifts",
            {
                search,
                company_id:
                    filters.company_id ??
                    "",
                status:
                    filters.status ??
                    "",
                ...values,
            },
            {
                preserveState:
                    true,

                replace:
                    true,
            },
        );
    };

    const submitSearch = (
        event:
            React.FormEvent,
    ) => {
        event.preventDefault();

        filter({
            search,
        });
    };

    const confirmDelete =
        () => {
            if (
                !deleteShift ||
                deleting
            ) {
                return;
            }

            setDeleting(true);

            router.delete(
                `/erp/attendance/shifts/${deleteShift.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeleteShift(
                                null,
                            ),

                    onFinish:
                        () =>
                            setDeleting(
                                false,
                            ),
                },
            );
        };

    return (
        <ERPLayout>
            <Head title="Attendance Shifts" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Clock3 className="size-4" />
                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold tracking-tight text-slate-900">
                            Shifts
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Define employee work schedules and biometric punch windows.
                        </p>
                    </div>

                    {can(
                        "erp.shifts.create",
                    ) && (
                        <Link
                            href="/erp/attendance/shifts/create"
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-medium text-white"
                        >
                            <Plus className="size-4" />
                            Create Shift
                        </Link>
                    )}
                </div>

                <section className="rounded-xl border bg-white p-4 shadow-sm">
                    <div className="grid gap-3 lg:grid-cols-[1fr_220px_180px]">
                        <form
                            onSubmit={
                                submitSearch
                            }
                            className="relative"
                        >
                            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />

                            <input
                                value={
                                    search
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setSearch(
                                        event.target.value,
                                    )
                                }
                                placeholder="Search shift..."
                                className="min-h-10 w-full rounded-lg border border-slate-200 pl-9 pr-3 text-sm"
                            />
                        </form>

                        <select
                            value={
                                filters.company_id ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                filter({
                                    company_id:
                                        event.target.value,
                                })
                            }
                            className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm"
                        >
                            <option value="">
                                All companies
                            </option>

                            {companies.map(
                                (
                                    company,
                                ) => (
                                    <option
                                        key={
                                            company.id
                                        }
                                        value={
                                            company.id
                                        }
                                    >
                                        {
                                            company.name
                                        }
                                    </option>
                                ),
                            )}
                        </select>

                        <select
                            value={
                                filters.status ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                filter({
                                    status:
                                        event.target.value,
                                })
                            }
                            className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm"
                        >
                            <option value="">
                                All statuses
                            </option>

                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>
                    </div>
                </section>

                <section className="overflow-hidden rounded-xl border bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">
                                        Shift
                                    </th>

                                    <th className="px-5 py-3">
                                        Company
                                    </th>

                                    <th className="px-5 py-3">
                                        Time
                                    </th>

                                    <th className="px-5 py-3">
                                        Working Days
                                    </th>

                                    <th className="px-5 py-3">
                                        Status
                                    </th>

                                    <th className="px-5 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y">
                                {shifts.data.map(
                                    (
                                        shift,
                                    ) => (
                                        <tr
                                            key={
                                                shift.id
                                            }
                                        >
                                            <td className="px-5 py-4">
                                                <div className="font-medium text-slate-900">
                                                    {
                                                        shift.name
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {
                                                        shift.code
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-5 py-4">
                                                {
                                                    shift.company.name
                                                }
                                            </td>

                                            <td className="px-5 py-4">
                                                <span className="font-medium">
                                                    {time(
                                                        shift.start_time,
                                                    )}
                                                    {" → "}
                                                    {time(
                                                        shift.end_time,
                                                    )}
                                                </span>

                                                {shift.crosses_midnight && (
                                                    <div className="mt-1 text-xs text-amber-600">
                                                        Overnight
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-5 py-4">
                                                <div className="flex flex-wrap gap-1">
                                                    {shift.working_days.map(
                                                        (
                                                            day,
                                                        ) => (
                                                            <span
                                                                key={
                                                                    day
                                                                }
                                                                className="rounded bg-slate-100 px-2 py-1 text-xs"
                                                            >
                                                                {
                                                                    dayLabels[
                                                                        day
                                                                    ]
                                                                }
                                                            </span>
                                                        ),
                                                    )}
                                                </div>
                                            </td>

                                            <td className="px-5 py-4">
                                                <span
                                                    className={
                                                        shift.status ===
                                                        "active"
                                                            ? "rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700"
                                                            : "rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600"
                                                    }
                                                >
                                                    {
                                                        shift.status
                                                    }
                                                </span>
                                            </td>

                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.shifts.edit",
                                                    ) && (
                                                        <Link
                                                            href={`/erp/attendance/shifts/${shift.id}/edit`}
                                                            className="inline-flex size-9 items-center justify-center rounded-lg border hover:bg-slate-50"
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.shifts.delete",
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDeleteShift(
                                                                    shift,
                                                                )
                                                            }
                                                            className="inline-flex size-9 items-center justify-center rounded-lg border text-red-600 hover:bg-red-50"
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ),
                                )}

                                {shifts.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                6
                                            }
                                            className="px-5 py-12 text-center text-slate-500"
                                        >
                                            No shifts found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {shifts.links && (
                        <div className="flex flex-wrap gap-2 border-t px-5 py-4">
                            {shifts.links.map(
                                (
                                    link,
                                    index,
                                ) => (
                                    <button
                                        key={
                                            index
                                        }
                                        disabled={
                                            !link.url
                                        }
                                        onClick={() => {
                                            if (
                                                link.url
                                            ) {
                                                router.visit(
                                                    link.url,
                                                );
                                            }
                                        }}
                                        className={`rounded-lg border px-3 py-1.5 text-sm ${
                                            link.active
                                                ? "bg-slate-900 text-white"
                                                : "bg-white text-slate-600"
                                        } disabled:opacity-40`}
                                        dangerouslySetInnerHTML={{
                                            __html:
                                                link.label,
                                        }}
                                    />
                                ),
                            )}
                        </div>
                    )}
                </section>
            </div>

            <DeleteConfirmDialog
                open={
                    deleteShift !==
                    null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (
                        !open &&
                        !deleting
                    ) {
                        setDeleteShift(
                            null,
                        );
                    }
                }}
                title="Delete shift?"
                itemName={
                    deleteShift?.name
                }
                processing={
                    deleting
                }
                onConfirm={
                    confirmDelete
                }
            />
        </ERPLayout>
    );
}