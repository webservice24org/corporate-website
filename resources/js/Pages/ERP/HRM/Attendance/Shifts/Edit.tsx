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

type Shift = {
    id: number;

    company_id: number;

    name: string;
    code: string;

    start_time: string;
    end_time: string;

    crosses_midnight: boolean;

    check_in_window_before_minutes:
        number;

    check_in_window_after_minutes:
        number;

    check_out_window_before_minutes:
        number;

    check_out_window_after_minutes:
        number;

    grace_minutes: number;

    early_leave_grace_minutes:
        number;

    minimum_work_minutes:
        number;

    half_day_minutes:
        number;

    overtime_after_minutes:
        number;

    break_minutes: number;

    break_is_paid: boolean;

    working_days: number[];

    status: string;

    notes:
        | string
        | null;
};

type Props = {
    shift: Shift;
    companies: Company[];
};

function formatTime(
    value: string,
): string {
    return value.substring(
        0,
        5,
    );
}

export default function Edit({
    shift,
    companies,
}: Props) {
    const {
        data,
        setData,
        put,
        errors,
        processing,
    } = useForm({
        company_id:
            String(
                shift.company_id,
            ),

        name:
            shift.name,

        code:
            shift.code,

        start_time:
            formatTime(
                shift.start_time,
            ),

        end_time:
            formatTime(
                shift.end_time,
            ),

        crosses_midnight:
            shift.crosses_midnight,

        check_in_window_before_minutes:
            shift.check_in_window_before_minutes,

        check_in_window_after_minutes:
            shift.check_in_window_after_minutes,

        check_out_window_before_minutes:
            shift.check_out_window_before_minutes,

        check_out_window_after_minutes:
            shift.check_out_window_after_minutes,

        grace_minutes:
            shift.grace_minutes,

        early_leave_grace_minutes:
            shift.early_leave_grace_minutes,

        minimum_work_minutes:
            shift.minimum_work_minutes,

        half_day_minutes:
            shift.half_day_minutes,

        overtime_after_minutes:
            shift.overtime_after_minutes,

        break_minutes:
            shift.break_minutes,

        break_is_paid:
            shift.break_is_paid,

        working_days:
            shift.working_days ??
            [],

        status:
            shift.status,

        notes:
            shift.notes ??
            "",
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        put(
            `/erp/attendance/shifts/${shift.id}`,
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Shift" />

            <div className="mx-auto max-w-6xl">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Clock3 className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold text-slate-900">
                            Edit Shift
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
                    submitLabel="Update Shift"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}