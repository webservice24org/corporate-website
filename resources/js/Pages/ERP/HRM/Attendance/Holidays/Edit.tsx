import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";
import type { FormEvent } from "react";

import AttendanceHolidayForm, {
    type HolidayFormData,
} from "@/Components/ERP/HRM/AttendanceHolidayForm";
import ERPLayout from "@/Layouts/erp-layout";

type Company = {
    id: number;
    name: string;
};

type Branch = {
    id: number;
    company_id: number;
    name: string;
};

type Holiday = {
    id: number;
    company_id: number;
    branch_id: number | null;
    name: string;
    holiday_date: string;
    holiday_type: string;
    is_paid: boolean;
    status: string;
    notes: string | null;
};

type Props = {
    holiday: Holiday;
    companies: Company[];
    branches: Branch[];
};

export default function Edit({
    holiday,
    companies,
    branches,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } = useForm<HolidayFormData>({
        company_id:
            String(
                holiday.company_id
            ),

        branch_id:
            holiday.branch_id
                ? String(
                    holiday.branch_id
                )
                : "",

        name:
            holiday.name,

        holiday_date:
            holiday.holiday_date
                .substring(0, 10),

        holiday_type:
            holiday.holiday_type,

        is_paid:
            holiday.is_paid,

        status:
            holiday.status,

        notes:
            holiday.notes ?? "",
    });

    function submit(
        event: FormEvent
    ) {
        event.preventDefault();

        put(
            `/erp/attendance/holidays/${holiday.id}`
        );
    }

    return (
        <ERPLayout>
            <Head title="Edit Holiday" />

            <div className="mx-auto max-w-5xl space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Edit Holiday
                    </h1>
                </div>

                <form
                    onSubmit={submit}
                    className="rounded-xl border bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-950"
                >
                    <AttendanceHolidayForm
                        data={data}
                        setData={setData}
                        errors={errors}
                        companies={companies}
                        branches={branches}
                    />

                    <div className="mt-6 flex gap-3">
                        <button
                            type="submit"
                            disabled={
                                processing
                            }
                            className="rounded-md bg-slate-950 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
                        >
                            Update Holiday
                        </button>

                        <Link
                            href="/erp/attendance/holidays"
                            className="rounded-md border px-4 py-2 text-sm font-medium"
                        >
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </ERPLayout>
    );
}