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

type Props = {
    companies: Company[];
    branches: Branch[];
};

export default function Create({
    companies,
    branches,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm<HolidayFormData>({
        company_id: "",
        branch_id: "",
        name: "",
        holiday_date: "",
        holiday_type: "company",
        is_paid: true,
        status: "active",
        notes: "",
    });

    function submit(
        event: FormEvent
    ) {
        event.preventDefault();

        post(
            "/erp/attendance/holidays"
        );
    }

    return (
        <ERPLayout>
            <Head title="Create Holiday" />

            <div className="mx-auto max-w-5xl space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Create Holiday
                    </h1>

                    <p className="mt-1 text-sm text-slate-500">
                        Add a company-wide or
                        branch-specific attendance
                        holiday.
                    </p>
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
                            Create Holiday
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