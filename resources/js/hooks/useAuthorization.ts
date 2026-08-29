import {
    usePage,
} from "@inertiajs/react";

import type {
    PageProps,
} from "@/types";

export function useAuthorization() {
    const user =
        usePage<PageProps>()
            .props.auth?.user;

    /*
     * Check one permission.
     */
    const can = (
        permission: string,
    ): boolean => {
        if (!user) {
            return false;
        }

        /*
         * Keep frontend behavior
         * consistent with Gate::before().
         */
        if (
            user.is_super_admin
        ) {
            return true;
        }

        return (
            user.permissions ??
            []
        ).includes(
            permission,
        );
    };

    /*
     * At least one permission.
     */
    const canAny = (
        permissions: string[],
    ): boolean => {
        return permissions.some(
            (permission) =>
                can(permission),
        );
    };

    /*
     * Every permission required.
     */
    const canAll = (
        permissions: string[],
    ): boolean => {
        return permissions.every(
            (permission) =>
                can(permission),
        );
    };

    /*
     * Direct role check.
     *
     * Prefer can() for application
     * authorization.
     */
    const hasRole = (
        role: string,
    ): boolean => {
        if (!user) {
            return false;
        }

        return (
            user.roles ?? []
        ).includes(role);
    };

    return {
        user,

        can,
        canAny,
        canAll,

        hasRole,

        isSuperAdmin:
            Boolean(
                user?.is_super_admin
            ),
    };
}