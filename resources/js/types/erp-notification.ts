export type ErpNotification = {
    id: string;

    type: string;

    title: string;

    message:
        string | null;

    url:
        string | null;

    lead_id:
        number | null;

    lead_number:
        string | null;

    read_at:
        string | null;

    created_at:
        string;
};

export type ErpNotificationShared = {
    unread_count: number;

    items:
        ErpNotification[];
};