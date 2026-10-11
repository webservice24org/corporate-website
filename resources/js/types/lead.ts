export type LeadCompany = {
    id: number;
    name: string;
};

export type LeadSourceSummary = {
    id: number;
    company_id?: number;
    name: string;
    code: string;
};

export type LeadStatusSummary = {
    id: number;
    company_id?: number;
    name: string;
    code: string;
    probability: number;
    is_won: boolean;
    is_lost: boolean;
};

export type LeadUserSummary = {
    id: number;
    name: string;
    email: string;
};

export type LeadActivity = {
    id: number;

    activity_type:
        | "call"
        | "meeting"
        | "email"
        | "task"
        | "follow_up"
        | "note";

    subject: string;

    description:
        string | null;

    status:
        | "planned"
        | "in_progress"
        | "completed"
        | "cancelled";

    priority:
        | "low"
        | "normal"
        | "high"
        | "urgent";

    scheduled_at:
        string | null;

    due_at:
        string | null;

    completed_at:
        string | null;

    outcome:
        string | null;

    created_at: string;

    assigned_to?:
        LeadUserSummary | null;

    created_by?:
        LeadUserSummary | null;
};

export type Lead = {
    id: number;

    company_id: number;

    lead_source_id:
        number | null;

    lead_status_id: number;

    owner_user_id:
        number | null;

    lead_number: string;

    lead_type:
        | "individual"
        | "company";

    company_name:
        string | null;

    contact_name: string;

    job_title:
        string | null;

    email:
        string | null;

    phone:
        string | null;

    alternate_phone:
        string | null;

    website:
        string | null;

    expected_value:
        string | number;

    currency: string;

    next_follow_up_at:
        string | null;

    address_line_1:
        string | null;

    address_line_2:
        string | null;

    city:
        string | null;

    state:
        string | null;

    postal_code:
        string | null;

    country:
        string | null;

    notes:
        string | null;

    is_active: boolean;

    converted_to_type?:
        string | null;

    converted_to_id?:
        number | null;

    converted_at?:
        string | null;

    converted_by?:
        number | null;

    conversion_notes?:
        string | null;

    company?:
        LeadCompany | null;

    source?:
        LeadSourceSummary | null;

    status?:
        LeadStatusSummary | null;

    owner?:
        LeadUserSummary | null;
};

export type LeadDetail =
    Lead & {
        activities:
            LeadActivity[];
    };

/*
 * The Lead conversion controller intentionally
 * sends a small normalized summary rather than
 * the full Lead database shape.
 */
export type LeadConversionSummary = {
    id: number;
    name: string;
    business_name: string;
    phone: string;
    email: string;
    address: string;

    converted_to_type?:
        string | null;

    converted_to_id?:
        number | null;

    converted_at?:
        string | null;

    converted_by?:
        number | null;

    conversion_notes?:
        string | null;
};
