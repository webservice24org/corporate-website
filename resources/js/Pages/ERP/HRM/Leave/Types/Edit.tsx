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

type LeaveType = {
    id: number;

    company_id: number;

    name: string;
    code: string;

    annual_allowance:
        | number
        | string;

    is_paid: boolean;

    allow_half_day: boolean;

    carry_forward: boolean;

    max_carry_forward:
        | number
        | string;

    requires_attachment: boolean;

    exclude_weekly_off: boolean;

    exclude_holidays: boolean;

    status: string;

    description:
        | string
        | null;
};

type Props = {
    leaveType: LeaveType;
    companies: Company[];
};

export default function Edit({
    leaveType,
    companies,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } = useForm<LeaveTypeFormData>({
        company_id:
            String(
                leaveType.company_id
            ),

        name:
            leaveType.name,

        code:
            leaveType.code,

        annual_allowance:
            String(
                leaveType.annual_allowance
            ),

        is_paid:
            Boolean(
                leaveType.is_paid
            ),

        allow_half_day:
            Boolean(
                leaveType.allow_half_day
            ),

        carry_forward:
            Boolean(
                leaveType.carry_forward
            ),

        max_carry_forward:
            String(
                leaveType.max_carry_forward
            ),

        requires_attachment:
            Boolean(
                leaveType.requires_attachment
            ),

        exclude_weekly_off:
            Boolean(
                leaveType.exclude_weekly_off
            ),

        exclude_holidays:
            Boolean(
                leaveType.exclude_holidays
            ),

        status:
            leaveType.status,

        description:
            leaveType.description ?? "",
    });

    const submit = (
        event: FormEvent
    ) => {
        event.preventDefault();

        put(
            `/erp/leave/types/${leaveType.id}`
        );
    };

    return (
        <ERPLayout>
            <Head
                title={`Edit ${leaveType.name}`}
            />

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
                            Edit Leave Type
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            {leaveType.name} (
                            {leaveType.code})
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
                                ? "Updating..."
                                : "Update Leave Type"}
                        </button>
                    </div>
                </form>
            </div>
        </ERPLayout>
    );
}