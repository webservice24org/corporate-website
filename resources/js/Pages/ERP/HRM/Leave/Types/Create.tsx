import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";
import { ArrowLeft } from "lucide-react";
import type { FormEvent } from "react";

import LeaveTypeForm, {
    type LeaveTypeFormData,
} from "@/Components/ERP/HRM/LeaveTypeForm";
import ERPLayout from "@/Layouts/erp-layout";

type Company = {
    id: number;
    name: string;
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
        processing,
        errors,
    } = useForm<LeaveTypeFormData>({
        company_id: "",
        name: "",
        code: "",
        annual_allowance: "0",
        is_paid: true,
        allow_half_day: true,
        carry_forward: false,
        max_carry_forward: "0",
        requires_attachment: false,
        exclude_weekly_off: true,
        exclude_holidays: true,
        status: "active",
        description: "",
    });

    const submit = (
        event: FormEvent
    ) => {
        event.preventDefault();

        post("/erp/leave/types");
    };

    return (
        <ERPLayout>
            <Head title="Create Leave Type" />

            <div className="mx-auto max-w-5xl space-y-6">
                <div className="flex items-center gap-4">
                    <Link
                        href="/erp/leave/types"
                        className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Create Leave Type
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Define entitlement and
                            calculation rules for a new
                            leave category.
                        </p>
                    </div>
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-6"
                >
                    <div className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                        <LeaveTypeForm
                            data={data}
                            setData={
                                setData
                            }
                            errors={
                                errors
                            }
                            companies={
                                companies
                            }
                        />
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link
                            href="/erp/leave/types"
                            className="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 px-4 text-sm font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                        >
                            Cancel
                        </Link>

                        <button
                            type="submit"
                            disabled={
                                processing
                            }
                            className="inline-flex h-10 items-center justify-center rounded-md bg-slate-900 px-5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50 dark:bg-white dark:text-slate-950"
                        >
                            {processing
                                ? "Saving..."
                                : "Create Leave Type"}
                        </button>
                    </div>
                </form>
            </div>
        </ERPLayout>
    );
}