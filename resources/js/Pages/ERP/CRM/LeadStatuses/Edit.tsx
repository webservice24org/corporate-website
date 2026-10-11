import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import type {
    FormEvent,
} from "react";

import {
    ArrowLeft,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

import LeadStatusForm from "./LeadStatusForm";

import type {
    Company,
    LeadStatusFormData,
} from "./LeadStatusForm";

type LeadStatus = {
    id: number;
    company_id: number;
    name: string;
    code: string;
    probability: number;
    is_won: boolean;
    is_lost: boolean;
    description:
        string | null;
    sort_order: number;
    status: boolean;
};

type Props = {
    leadStatus:
        LeadStatus;

    companies:
        Company[];
};

export default function Edit({
    leadStatus,
    companies,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } =
        useForm<LeadStatusFormData>({
            company_id:
                String(
                    leadStatus.company_id
                ),

            name:
                leadStatus.name,

            code:
                leadStatus.code,

            probability:
                String(
                    leadStatus.probability
                ),

            is_won:
                leadStatus.is_won,

            is_lost:
                leadStatus.is_lost,

            description:
                leadStatus.description ??
                "",

            sort_order:
                String(
                    leadStatus.sort_order
                ),

            status:
                leadStatus.status,
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        put(
            `/erp/crm/lead-statuses/${leadStatus.id}`
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Lead Status" />

            <div className="mx-auto max-w-5xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href="/erp/crm/lead-statuses"
                        className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold">
                            Edit Lead Status
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            {
                                leadStatus.name
                            }{" "}
                            ·{" "}
                            {
                                leadStatus.code
                            }
                        </p>
                    </div>
                </div>

                <LeadStatusForm
                    data={data}
                    setData={setData}
                    companies={
                        companies
                    }
                    errors={
                        errors
                    }
                    processing={
                        processing
                    }
                    submitLabel="Update Lead Status"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}