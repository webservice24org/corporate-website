import AttendanceShiftForm from "@/Components/ERP/HRM/AttendanceShiftForm";
import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Clock3,
} from "lucide-react";

import type {
    FormEvent,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

type Props = {
    companies: Company[];
};

export default function Create({
    companies,
}: Props) {
    const {
        data,
        setData,
        post,
        errors,
        processing,
    } = useForm({
        company_id:
            companies.length
                ? String(
                      companies[0].id,
                  )
                : "",

        name: "",
        code: "",

        start_time:
            "09:00",

        end_time:
            "18:00",

        crosses_midnight:
            false,

        check_in_window_before_minutes:
            180,

        check_in_window_after_minutes:
            240,

        check_out_window_before_minutes:
            240,

        check_out_window_after_minutes:
            360,

        grace_minutes:
            10,

        early_leave_grace_minutes:
            10,

        minimum_work_minutes:
            480,

        half_day_minutes:
            240,

        overtime_after_minutes:
            0,

        break_minutes:
            60,

        break_is_paid:
            false,

        working_days: [
            0,
            1,
            2,
            3,
            4,
        ] as number[],

        status:
            "active",

        notes:
            "",
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        post(
            "/erp/attendance/shifts",
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Shift" />

            <div className="mx-auto max-w-6xl">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Clock3 className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold text-slate-900">
                            Create Shift
                        </h1>
                    </div>

                    <Link
                        href="/erp/attendance/shifts"
                        className="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 text-sm"
                    >
                        <ArrowLeft className="size-4" />

                        Shifts
                    </Link>
                </div>

                <AttendanceShiftForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    companies={
                        companies
                    }
                    processing={
                        processing
                    }
                    submitLabel="Create Shift"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}