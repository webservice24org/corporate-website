import { useEffect, type FormEvent, type ReactNode } from "react";

export type LeadOption = {
    id: number;
    company_id: number;
    lead_number: string;
    lead_type: "individual" | "company";
    company_name: string | null;
    contact_name: string;
    company?: {
        id: number;
        name: string;
    } | null;
};

export type CustomerOption = {
    id: number;
    name: string;
    company_name?: string | null;
};

export type OpportunityOption = {
    id: number;
    company_id: number;
    title: string;
    opportunity_number: string | null;
};

export type UserOption = {
    id: number;
    name: string;
    email: string;
};

export type SubjectType = "lead" | "customer" | "opportunity";

export type ActivityFormData = {
    subject_type: SubjectType;
    subject_id: number | null;
    lead_id?: string; // Kept for legacy compatibility if needed
    activity_type:
        | "call"
        | "meeting"
        | "email"
        | "task"
        | "follow_up"
        | "note";
    subject: string;
    description: string;
    status: "planned" | "in_progress" | "completed" | "cancelled";
    priority: "low" | "normal" | "high" | "urgent";
    assigned_to_user_id: string;
    scheduled_at: string;
    due_at: string;
    reminder_at: string;
    completed_at: string;
    outcome: string;
};

type Errors = Record<string, string | undefined>;

type Props = {
    data: ActivityFormData;
    setData: <K extends keyof ActivityFormData>(
        key: K,
        value: ActivityFormData[K]
    ) => void;
    leads?: LeadOption[];
    customers?: CustomerOption[];
    opportunities?: OpportunityOption[];
    users: UserOption[];
    errors: Errors;
    processing: boolean;
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    lockSubject?: boolean;
};

export default function ActivityForm({
    data,
    setData,
    leads = [],
    customers = [],
    opportunities = [],
    users,
    errors,
    processing,
    submitLabel,
    onSubmit,
    lockSubject = false,
}: Props) {
    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-800";

    useEffect(() => {
        if (typeof window === "undefined") return;

        const params = new URLSearchParams(window.location.search);
        const opportunityIdParam = params.get("opportunity_id");
        const leadIdParam = params.get("lead_id");
        const customerIdParam = params.get("customer_id");

        if (opportunityIdParam) {
            setData("subject_type", "opportunity");
            setData("subject_id", Number(opportunityIdParam));
        } else if (leadIdParam) {
            setData("subject_type", "lead");
            setData("subject_id", Number(leadIdParam));
        } else if (customerIdParam) {
            setData("subject_type", "customer");
            setData("subject_id", Number(customerIdParam));
        }
    }, []);

    const changeStatus = (value: ActivityFormData["status"]) => {
        setData("status", value);

        if (value !== "completed") {
            setData("completed_at", "");
        }
    };

    return (
        <form onSubmit={onSubmit} className="space-y-6">
            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="mb-5">
                    <h2 className="text-base font-semibold">Activity</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        Record or schedule CRM communication, work and follow-up.
                    </p>
                </div>

                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    {!lockSubject && (
                        <>
                            <Field
                                label="Subject Type"
                                error={errors.subject_type}
                                required
                            >
                                <select
                                    value={data.subject_type ?? "lead"}
                                    onChange={(event) => {
                                        setData(
                                            "subject_type",
                                            event.target.value as SubjectType
                                        );
                                        setData("subject_id", null);
                                    }}
                                    className={inputClass}
                                >
                                    <option value="lead">Lead</option>
                                    <option value="customer">Customer</option>
                                    <option value="opportunity">Opportunity</option>
                                </select>
                            </Field>

                            <Field
                                label="Subject Target"
                                error={errors.subject_id}
                                required
                            >
                                {data.subject_type === "opportunity" && (
                                    <select
                                        value={data.subject_id ?? ""}
                                        onChange={(event) =>
                                            setData(
                                                "subject_id",
                                                event.target.value
                                                    ? Number(event.target.value)
                                                    : null
                                            )
                                        }
                                        className={inputClass}
                                    >
                                        <option value="">Select Opportunity</option>
                                        {opportunities.map((opportunity) => (
                                            <option
                                                key={opportunity.id}
                                                value={opportunity.id}
                                            >
                                                {opportunity.opportunity_number ??
                                                    `Opportunity #${opportunity.id}`}{" "}
                                                · {opportunity.title}
                                            </option>
                                        ))}
                                    </select>
                                )}

                                {data.subject_type === "customer" && (
                                    <select
                                        value={data.subject_id ?? ""}
                                        onChange={(event) =>
                                            setData(
                                                "subject_id",
                                                event.target.value
                                                    ? Number(event.target.value)
                                                    : null
                                            )
                                        }
                                        className={inputClass}
                                    >
                                        <option value="">Select Customer</option>
                                        {customers.map((customer) => (
                                            <option
                                                key={customer.id}
                                                value={customer.id}
                                            >
                                                {customer.name}
                                                {customer.company_name
                                                    ? ` (${customer.company_name})`
                                                    : ""}
                                            </option>
                                        ))}
                                    </select>
                                )}

                                {(data.subject_type === "lead" ||
                                    !data.subject_type) && (
                                    <select
                                        value={data.subject_id ?? ""}
                                        onChange={(event) =>
                                            setData(
                                                "subject_id",
                                                event.target.value
                                                    ? Number(event.target.value)
                                                    : null
                                            )
                                        }
                                        className={inputClass}
                                    >
                                        <option value="">Select Lead</option>
                                        {leads.map((lead) => (
                                            <option
                                                key={lead.id}
                                                value={lead.id}
                                            >
                                                {lead.lead_number} —{" "}
                                                {lead.lead_type === "company"
                                                    ? lead.company_name ??
                                                      lead.contact_name
                                                    : lead.contact_name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </Field>
                        </>
                    )}

                    <Field
                        label="Activity Type"
                        error={errors.activity_type}
                        required
                    >
                        <select
                            value={data.activity_type}
                            onChange={(event) =>
                                setData(
                                    "activity_type",
                                    event.target.value as ActivityFormData["activity_type"]
                                )
                            }
                            className={inputClass}
                        >
                            <option value="call">Call</option>
                            <option value="meeting">Meeting</option>
                            <option value="email">Email</option>
                            <option value="task">Task</option>
                            <option value="follow_up">Follow-up</option>
                            <option value="note">Note</option>
                        </select>
                    </Field>

                    <Field label="Status" error={errors.status} required>
                        <select
                            value={data.status}
                            onChange={(event) =>
                                changeStatus(
                                    event.target.value as ActivityFormData["status"]
                                )
                            }
                            className={inputClass}
                        >
                            <option value="planned">Planned</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </Field>

                    <Field label="Priority" error={errors.priority} required>
                        <select
                            value={data.priority}
                            onChange={(event) =>
                                setData(
                                    "priority",
                                    event.target.value as ActivityFormData["priority"]
                                )
                            }
                            className={inputClass}
                        >
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </Field>

                    <Field
                        label="Assigned To"
                        error={errors.assigned_to_user_id}
                    >
                        <select
                            value={data.assigned_to_user_id}
                            onChange={(event) =>
                                setData("assigned_to_user_id", event.target.value)
                            }
                            className={inputClass}
                        >
                            <option value="">Unassigned</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>

                <div className="mt-5">
                    <Field label="Subject" error={errors.subject} required>
                        <input
                            value={data.subject}
                            onChange={(event) =>
                                setData("subject", event.target.value)
                            }
                            placeholder="e.g. Discuss ERP proposal"
                            className={inputClass}
                        />
                    </Field>
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <h2 className="mb-5 text-base font-semibold">Schedule</h2>

                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                    <Field label="Scheduled At" error={errors.scheduled_at}>
                        <input
                            type="datetime-local"
                            value={data.scheduled_at}
                            onChange={(event) =>
                                setData("scheduled_at", event.target.value)
                            }
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Due At" error={errors.due_at}>
                        <input
                            type="datetime-local"
                            value={data.due_at}
                            onChange={(event) =>
                                setData("due_at", event.target.value)
                            }
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Reminder At" error={errors.reminder_at}>
                        <input
                            type="datetime-local"
                            value={data.reminder_at}
                            onChange={(event) =>
                                setData("reminder_at", event.target.value)
                            }
                            className={inputClass}
                        />
                    </Field>

                    {data.status === "completed" && (
                        <Field label="Completed At" error={errors.completed_at}>
                            <input
                                type="datetime-local"
                                value={data.completed_at}
                                onChange={(event) =>
                                    setData("completed_at", event.target.value)
                                }
                                className={inputClass}
                            />
                        </Field>
                    )}
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="grid gap-6 lg:grid-cols-2">
                    <Field label="Description" error={errors.description}>
                        <textarea
                            rows={6}
                            value={data.description}
                            onChange={(event) =>
                                setData("description", event.target.value)
                            }
                            placeholder="Activity details..."
                            className="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-800 dark:bg-slate-950"
                        />
                    </Field>

                    <Field label="Outcome" error={errors.outcome}>
                        <textarea
                            rows={6}
                            value={data.outcome}
                            onChange={(event) =>
                                setData("outcome", event.target.value)
                            }
                            placeholder="Result, decision, next action..."
                            className="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-800 dark:bg-slate-950"
                        />
                    </Field>
                </div>
            </section>

            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={processing}
                    className="h-10 rounded-md bg-slate-900 px-5 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
                >
                    {processing ? "Saving..." : submitLabel}
                </button>
            </div>
        </form>
    );
}

function Field({
    label,
    error,
    required = false,
    children,
}: {
    label: string;
    error?: string;
    required?: boolean;
    children: ReactNode;
}) {
    return (
        <div>
            <label className="mb-2 block text-sm font-medium">
                {label}
                {required && <span className="ml-1 text-red-500">*</span>}
            </label>
            {children}
            {error && <p className="mt-1.5 text-sm text-red-600">{error}</p>}
        </div>
    );
}