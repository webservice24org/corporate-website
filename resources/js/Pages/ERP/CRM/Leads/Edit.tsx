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

import type { Lead } from "@/types/lead";

type Props = {
    lead: Lead;
    companies: Company[];
    sources: LeadSource[];
    statuses: LeadStatus[];
    users: UserOption[];
};

function dateTimeLocal(
    value:
        string | null
): string {
    if (!value) {
        return "";
    }

    return value
        .replace(" ", "T")
        .slice(0, 16);
}

export default function Edit({
    lead,
    companies,
    sources,
    statuses,
    users,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } =
        useForm<LeadFormData>({
            company_id:
                String(
                    lead.company_id
                ),

            lead_number:
                lead.lead_number,

            lead_source_id:
                lead.lead_source_id
                    ? String(
                          lead.lead_source_id
                      )
                    : "",

            lead_status_id:
                String(
                    lead.lead_status_id
                ),

            owner_user_id:
                lead.owner_user_id
                    ? String(
                          lead.owner_user_id
                      )
                    : "",

            lead_type:
                lead.lead_type,

            company_name:
                lead.company_name ??
                "",

            contact_name:
                lead.contact_name,

            job_title:
                lead.job_title ??
                "",

            email:
                lead.email ??
                "",

            phone:
                lead.phone ??
                "",

            alternate_phone:
                lead.alternate_phone ??
                "",

            website:
                lead.website ??
                "",

            expected_value:
                String(
                    lead.expected_value
                ),

            currency:
                lead.currency,

            next_follow_up_at:
                dateTimeLocal(
                    lead.next_follow_up_at
                ),

            address_line_1:
                lead.address_line_1 ??
                "",

            address_line_2:
                lead.address_line_2 ??
                "",

            city:
                lead.city ??
                "",

            state:
                lead.state ??
                "",

            postal_code:
                lead.postal_code ??
                "",

            country:
                lead.country ??
                "",

            notes:
                lead.notes ??
                "",

            is_active:
                lead.is_active,
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        put(
            `/erp/crm/leads/${lead.id}`
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Lead" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href={`/erp/crm/leads/${lead.id}`}
                        className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold">
                            Edit Lead
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            {
                                lead.lead_number
                            }{" "}
                            ·{" "}
                            {
                                lead.contact_name
                            }
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
                    submitLabel="Update Lead"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}