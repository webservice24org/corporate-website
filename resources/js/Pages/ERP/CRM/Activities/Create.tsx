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

import ActivityForm from "./ActivityForm";

import type {
    ActivityFormData,
    LeadOption,
    UserOption,
} from "./ActivityForm";

type SubjectType =
    ActivityFormData["subject_type"];

type GenericSubject = {
    id: number;
    name?: string | null;
    business_name?: string | null;
    title?: string | null;
    lead_number?: string | null;
    lead_type?:
        | "individual"
        | "company";
    company_name?: string | null;
    contact_name?: string | null;
};

type Props = {
    subject:
        GenericSubject | null;

    subject_type:
        SubjectType | null;

    subject_id:
        number | null;

    leads:
        LeadOption[];

    users:
        UserOption[];
};

export default function Create({
    subject,
    subject_type,
    subject_id,
    leads,
    users,
}: Props) {
    const initialSubjectType:
        SubjectType =
        subject_type ??
        "lead";

    const initialSubjectId =
        subject_id ?? 0;

    const initialLeadId =
        initialSubjectType ===
            "lead" &&
        initialSubjectId > 0
            ? String(
                  initialSubjectId
              )
            : "";

    const {
        data,
        setData,
        post,
        processing,
        errors,
    } =
        useForm<ActivityFormData>({
            subject_type:
                initialSubjectType,

            subject_id:
                initialSubjectId,

            lead_id:
                initialLeadId,

            activity_type:
                "follow_up",

            subject: "",

            description: "",

            status:
                "planned",

            priority:
                "normal",

            assigned_to_user_id:
                "",

            scheduled_at:
                "",

            due_at: "",

            reminder_at:
                "",

            completed_at:
                "",

            outcome: "",
        });

    const submit = (
        event:
            FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        post(
            "/erp/crm/activities"
        );
    };

    const backHref =
        subject &&
        subject_type
            ? subjectUrl(
                  subject_type,
                  subject.id
              )
            : "/erp/crm/activities";

    return (
        <ERPLayout>
            <Head title="Create CRM Activity" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href={
                            backHref
                        }
                        className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold">
                            Create Activity
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            {subject &&
                            subject_type
                                ? `Create a CRM activity for ${subjectLabel(
                                      subject_type,
                                      subject
                                  )}.`
                                : "Create a CRM activity."}
                        </p>
                    </div>
                </div>

                <ActivityForm
                    data={data}
                    setData={setData}
                    leads={leads}
                    users={users}
                    errors={errors}
                    processing={
                        processing
                    }
                    submitLabel="Create Activity"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}

function subjectUrl(
    type: SubjectType,
    id: number
): string {
    if (
        type === "lead"
    ) {
        return `/erp/crm/leads/${id}`;
    }

    if (
        type === "customer"
    ) {
        return `/erp/crm/customers/${id}`;
    }

    return `/erp/crm/opportunities/${id}`;
}

function subjectLabel(
    type: SubjectType,
    subject: GenericSubject
): string {
    if (
        type === "lead"
    ) {
        return (
            subject.lead_number ??
            subject.company_name ??
            subject.contact_name ??
            `Lead #${subject.id}`
        );
    }

    if (
        type === "customer"
    ) {
        return (
            subject.business_name ??
            subject.name ??
            `Customer #${subject.id}`
        );
    }

    return (
        subject.title ??
        `Opportunity #${subject.id}`
    );
}
