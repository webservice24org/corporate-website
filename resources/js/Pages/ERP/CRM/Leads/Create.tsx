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

import LeadForm from "./LeadForm";

import type {
    Company,
    LeadFormData,
    LeadSource,
    LeadStatus,
    UserOption,
} from "./LeadForm";

type Props = {
    companies: Company[];
    sources: LeadSource[];
    statuses: LeadStatus[];
    users: UserOption[];
};

export default function Create({
    companies,
    sources,
    statuses,
    users,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } =
        useForm<LeadFormData>({
            company_id: "",
            lead_number: "",
            lead_source_id: "",
            lead_status_id: "",
            owner_user_id: "",

            lead_type:
                "individual",

            company_name: "",
            contact_name: "",
            job_title: "",

            email: "",
            phone: "",
            alternate_phone: "",
            website: "",

            expected_value: "0",
            currency: "BDT",

            next_follow_up_at:
                "",

            address_line_1: "",
            address_line_2: "",
            city: "",
            state: "",
            postal_code: "",
            country:
                "Bangladesh",

            notes: "",

            is_active: true,
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        post(
            "/erp/crm/leads"
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Lead" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href="/erp/crm/leads"
                        className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold">
                            Create Lead
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Add a new
                            commercial
                            prospect to CRM.
                        </p>
                    </div>
                </div>

                <LeadForm
                    data={data}
                    setData={setData}
                    companies={
                        companies
                    }
                    sources={
                        sources
                    }
                    statuses={
                        statuses
                    }
                    users={users}
                    errors={errors}
                    processing={
                        processing
                    }
                    submitLabel="Create Lead"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}