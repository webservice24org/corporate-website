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
    } =
        useForm<LeadStatusFormData>({
            company_id: "",
            name: "",
            code: "",
            probability: "0",
            is_won: false,
            is_lost: false,
            description: "",
            sort_order: "0",
            status: true,
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        post(
            "/erp/crm/lead-statuses"
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Lead Status" />

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
                            Create Lead Status
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Create a CRM
                            pipeline stage.
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
                    submitLabel="Create Lead Status"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}