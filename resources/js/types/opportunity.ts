export type OpportunityStage = {
    id: number;
    company_id: number;
    name: string;
    code: string;
    probability: number;
    is_won: boolean;
    is_lost: boolean;
};

export type OpportunityParty = {
    id: number;
    company_id: number;
    name?: string;
    business_name?: string | null;
    customer_code?: string | null;
    dealer_code?: string | null;
    seller_code?: string | null;
};

export type Opportunity = {
    id: number;
    company_id: number;
    opportunity_number: string | null;
    lead_id: number | null;
    party_type: string;
    party_id: number;
    opportunity_stage_id: number;
    salesperson_user_id: number | null;
    title: string;
    description: string | null;
    expected_value: string | number;
    currency: string;
    probability: number;
    expected_close_date: string | null;
    won_at: string | null;
    lost_at: string | null;
    lost_reason: string | null;
    is_active: boolean;
    notes: string | null;

    company?: { id: number; name: string } | null;
    stage?: OpportunityStage | null;
    salesperson?: { id: number; name: string; email: string } | null;
    lead?: {
        id: number;
        lead_number: string;
        contact_name: string;
        company_name: string | null;
    } | null;
    party?: OpportunityParty | null;
    activities?: OpportunityActivity[];
};

export type OpportunityFormData = {
    company_id: number | null;
    lead_id: number | null;
    party_type: "customer" | "dealer" | "seller";
    party_id: number | null;
    opportunity_stage_id: number | null;
    salesperson_user_id: number | null;
    title: string;
    description: string;
    expected_value: number;
    currency: string;
    probability: number | null;
    expected_close_date: string;
    lost_reason: string;
    is_active: boolean;
    notes: string;
};

export type OpportunityActivity = {
    id: number;
    activity_type:
        | "call"
        | "meeting"
        | "email"
        | "task"
        | "follow_up"
        | "note";
    subject: string;
    description: string | null;
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
    scheduled_at: string | null;
    due_at: string | null;
    completed_at: string | null;
    outcome: string | null;
    created_at: string;

    assigned_to?: {
        id: number;
        name: string;
        email: string;
    } | null;

    created_by?: {
        id: number;
        name: string;
        email: string;
    } | null;
};
