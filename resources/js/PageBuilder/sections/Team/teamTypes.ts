export type TeamSocial = {
    icon?: string;
    url?: string;
};

export type TeamMember = {
    image?: string;

    name?: string;

    designation?: string;

    show_bio?: boolean;
    bio?: string;

    show_contact?: boolean;

    email?: string;
    phone?: string;

    show_social?: boolean;

    social_links?:
        TeamSocial[];
};