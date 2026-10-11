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

type Activity = {
    id: number;

    activityable_type:
        string;

    activityable_id:
        number;

    activity_type:
        ActivityFormData["activity_type"];

    subject: string;

    description:
        string | null;

    status:
        ActivityFormData["status"];

    priority:
        ActivityFormData["priority"];

    assigned_to_user_id:
        number | null;

    scheduled_at:
        string | null;

    due_at:
        string | null;

    reminder_at:
        string | null;

    completed_at:
        string | null;

    outcome:
        string | null;
};

type GenericSubject = {
    id: number;
};

type Props = {
    activity:
        Activity;

    subject:
        GenericSubject;

    leads:
        LeadOption[];

    users:
        UserOption[];
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

function resolveSubjectType(
    activityableType:
        string
): SubjectType {
    const normalized =
        activityableType
            .toLowerCase();

    if (
        normalized.includes(
            "opportunity"
        )
    ) {
        return "opportunity";
    }

    if (
        normalized.includes(
            "customer"
        )
    ) {
        return "customer";
    }

    return "lead";
}

export default function Edit({
    activity,
    subject,
    leads,
    users,
}: Props) {
    const initialSubjectType =
        resolveSubjectType(
            activity.activityable_type
        );

    const initialLeadId =
        initialSubjectType ===
        "lead"
            ? String(
                  activity.activityable_id
              )
            : "";

    const {
        data,
        setData,
        put,
        processing,
        errors,
    } =
        useForm<ActivityFormData>({
            subject_type:
                initialSubjectType,

            subject_id:
                activity.activityable_id,

            lead_id:
                initialLeadId,

            activity_type:
                activity.activity_type,

            subject:
                activity.subject,

            description:
                activity.description ??
                "",

            status:
                activity.status,

            priority:
                activity.priority,

            assigned_to_user_id:
                activity.assigned_to_user_id
                    ? String(
                          activity.assigned_to_user_id
                      )
                    : "",

            scheduled_at:
                dateTimeLocal(
                    activity.scheduled_at
                ),

            due_at:
                dateTimeLocal(
                    activity.due_at
                ),

            reminder_at:
                dateTimeLocal(
                    activity.reminder_at
                ),

            completed_at:
                dateTimeLocal(
                    activity.completed_at
                ),

            outcome:
                activity.outcome ??
                "",
        });

    const submit = (
        event:
            FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        put(
            `/erp/crm/activities/${activity.id}`
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit CRM Activity" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href={
                            subjectUrl(
                                initialSubjectType,
                                subject.id
                            )
                        }
                        className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold">
                            Edit Activity
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            {
                                activity.subject
                            }
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
                    submitLabel="Update Activity"
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
