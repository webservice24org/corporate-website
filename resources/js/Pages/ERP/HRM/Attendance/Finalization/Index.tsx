import {
    Head,
    useForm,
} from "@inertiajs/react";
import {
    CalendarCheck,
    Play,
} from "lucide-react";
import type { FormEvent } from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

type Company = {
    id: number;
    name: string;
    timezone: string | null;
};

type Run = {
    id: number;
    attendance_date: string;
    status: string;
    employees_checked: number;
    absent_created: number;
    off_day_created: number;
    off_day_work_updated: number;
    existing_attendance_skipped: number;
    manual_records_skipped: number;
    no_shift_skipped: number;
    created_at: string;

    company: {
        id: number;
        name: string;
    };

    run_by: {
        id: number;
        name: string;
    } | null;
};

type Props = {
    companies: Company[];

    runs: {
        data: Run[];
    };
};

export default function Index({
    companies,
    runs,
}: Props) {
    const { can } =
        useAuthorization();

    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm({
        company_id: "",
        attendance_date: "",
    });

    function submit(
        event: FormEvent
    ) {
        event.preventDefault();

        if (
            !window.confirm(
                "Finalize this attendance date? Missing attendance on working days will be marked absent."
            )
        ) {
            return;
        }

        post(
            "/erp/attendance/finalization",
            {
                preserveScroll: true,
            }
        );
    }

    return (
        <ERPLayout>
            <Head title="Attendance Day Finalization" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Attendance Day
                        Finalization
                    </h1>

                    <p className="mt-1 text-sm text-slate-500">
                        Close a completed attendance
                        date and generate missing
                        absent or off-day records.
                    </p>
                </div>

                <div className="rounded-xl border bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
                    Finalization currently runs only
                    for past dates. Existing manual
                    records and biometric attendance
                    are protected. Approved leave
                    reconciliation will be added with
                    the Leave module.
                </div>

                {can(
                    "erp.attendance-finalization.run"
                ) && (
                    <form
                        onSubmit={submit}
                        className="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950"
                    >
                        <div className="mb-4 flex items-center gap-2 font-semibold">
                            <CalendarCheck className="size-5" />
                            Finalize Date
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Company
                                </label>

                                <select
                                    value={
                                        data.company_id
                                    }
                                    onChange={(event) =>
                                        setData(
                                            "company_id",
                                            event.target.value
                                        )
                                    }
                                    className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                >
                                    <option value="">
                                        Select company
                                    </option>

                                    {companies.map(
                                        (company) => (
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
                                        )
                                    )}
                                </select>

                                {errors.company_id && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {
                                            errors.company_id
                                        }
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Attendance Date
                                </label>

                                <input
                                    type="date"
                                    value={
                                        data.attendance_date
                                    }
                                    onChange={(event) =>
                                        setData(
                                            "attendance_date",
                                            event.target.value
                                        )
                                    }
                                    className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                />

                                {errors.attendance_date && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {
                                            errors.attendance_date
                                        }
                                    </p>
                                )}
                            </div>
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="mt-4 inline-flex items-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
                        >
                            <Play className="size-4" />

                            {processing
                                ? "Finalizing..."
                                : "Finalize Attendance"}
                        </button>
                    </form>
                )}

                <div className="overflow-hidden rounded-xl border bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <div className="border-b px-5 py-4 dark:border-slate-800">
                        <h2 className="font-semibold">
                            Finalization History
                        </h2>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1100px] text-sm">
                            <thead className="border-b bg-slate-50 dark:border-slate-800 dark:bg-slate-900/50">
                                <tr>
                                    <th className="px-4 py-3 text-left">
                                        Date
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Company
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Checked
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Absent
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Off Day
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Off Day Work
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Existing
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Manual
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        No Shift
                                    </th>

                                    <th className="px-4 py-3 text-left">
                                        Run By
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y dark:divide-slate-800">
                                {runs.data.map(
                                    (run) => (
                                        <tr
                                            key={
                                                run.id
                                            }
                                        >
                                            <td className="px-4 py-3">
                                                {run.attendance_date.substring(
                                                    0,
                                                    10
                                                )}
                                            </td>

                                            <td className="px-4 py-3">
                                                {
                                                    run.company.name
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                {
                                                    run.employees_checked
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                {
                                                    run.absent_created
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                {
                                                    run.off_day_created
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                {
                                                    run.off_day_work_updated
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                {
                                                    run.existing_attendance_skipped
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                {
                                                    run.manual_records_skipped
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                {
                                                    run.no_shift_skipped
                                                }
                                            </td>

                                            <td className="px-4 py-3">
                                                {run.run_by
                                                    ?.name ??
                                                    "—"}
                                            </td>
                                        </tr>
                                    )
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </ERPLayout>
    );
}