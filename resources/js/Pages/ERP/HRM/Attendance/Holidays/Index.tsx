import {
    Head,
    Link,
    router,
} from "@inertiajs/react";
import {
    CalendarDays,
    Pencil,
    Plus,
    Trash2,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

type Holiday = {
    id: number;
    name: string;
    holiday_date: string;
    holiday_type: string;
    is_paid: boolean;
    status: string;

    company: {
        id: number;
        name: string;
    };

    branch: {
        id: number;
        name: string;
    } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Props = {
    holidays: {
        data: Holiday[];
        links: PaginationLink[];
        total: number;
    };
};

export default function Index({
    holidays,
}: Props) {
    const { can } =
        useAuthorization();

    function destroyHoliday(
        holiday: Holiday
    ) {
        if (
            !window.confirm(
                `Delete "${holiday.name}"?`
            )
        ) {
            return;
        }

        router.delete(
            `/erp/attendance/holidays/${holiday.id}`,
            {
                preserveScroll: true,
            }
        );
    }

    return (
        <ERPLayout>
            <Head title="Holiday Calendar" />

            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Holiday Calendar
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Manage company-wide and
                            branch-specific attendance
                            holidays.
                        </p>
                    </div>

                    {can(
                        "erp.attendance-holidays.create"
                    ) && (
                        <Link
                            href="/erp/attendance/holidays/create"
                            className="inline-flex items-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                        >
                            <Plus className="size-4" />
                            Add Holiday
                        </Link>
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[900px] text-sm">
                            <thead className="border-b bg-slate-50 dark:border-slate-800 dark:bg-slate-900/50">
                                <tr>
                                    <th className="px-4 py-3 text-left">
                                        Holiday
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Date
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Company
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Branch
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Type
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Paid
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Status
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y dark:divide-slate-800">
                                {holidays.data.map(
                                    (holiday) => (
                                        <tr
                                            key={
                                                holiday.id
                                            }
                                        >
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2 font-medium">
                                                    <CalendarDays className="size-4 text-slate-400" />
                                                    {
                                                        holiday.name
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-4 py-3">
                                                {holiday.holiday_date.substring(
                                                    0,
                                                    10
                                                )}
                                            </td>

                                            <td className="px-4 py-3">
                                                {
                                                    holiday.company.name
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                {holiday.branch
                                                    ?.name ??
                                                    "Company-wide"}
                                            </td>

                                            <td className="px-4 py-3 capitalize">
                                                {
                                                    holiday.holiday_type
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                {holiday.is_paid
                                                    ? "Yes"
                                                    : "No"}
                                            </td>

                                            <td className="px-4 py-3 capitalize">
                                                {
                                                    holiday.status
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.attendance-holidays.edit"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/attendance/holidays/${holiday.id}/edit`}
                                                            className="rounded-md border p-2"
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.attendance-holidays.delete"
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                destroyHoliday(
                                                                    holiday
                                                                )
                                                            }
                                                            className="rounded-md border p-2 text-red-600"
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {holidays.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-4 py-12 text-center text-slate-500"
                                        >
                                            No holidays
                                            found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </ERPLayout>
    );
}