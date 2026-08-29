export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;

    roles: string[];
    permissions: string[];

    is_super_admin: boolean;

    profile_photo?: string | null;
    designation?: string | null;
    department?: string | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = Record<string, unknown> &
    T & {
        auth: {
            user: User | null;
        };
    };