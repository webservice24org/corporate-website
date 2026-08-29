import {
    Check,
    ShieldCheck,
} from "lucide-react";

type PermissionGroups = Record<
    string,
    string[]
>;

type Props = {
    name: string;

    setName: (
        value: string,
    ) => void;

    permissions: string[];

    setPermissions: (
        permissions: string[],
    ) => void;

    permissionGroups:
        PermissionGroups;

    nameError?: string;

    protectedRole?: boolean;
};

export default function RoleForm({
    name,
    setName,
    permissions,
    setPermissions,
    permissionGroups,
    nameError,
    protectedRole = false,
}: Props) {
    const togglePermission = (
        permission: string,
    ) => {
        if (
            permissions.includes(
                permission,
            )
        ) {
            setPermissions(
                permissions.filter(
                    (item) =>
                        item !==
                        permission,
                ),
            );

            return;
        }

        setPermissions([
            ...permissions,
            permission,
        ]);
    };

    const toggleGroup = (
        groupPermissions: string[],
    ) => {
        const allSelected =
            groupPermissions.every(
                (permission) =>
                    permissions.includes(
                        permission,
                    ),
            );

        if (allSelected) {
            setPermissions(
                permissions.filter(
                    (permission) =>
                        !groupPermissions.includes(
                            permission,
                        ),
                ),
            );

            return;
        }

        setPermissions(
            Array.from(
                new Set([
                    ...permissions,
                    ...groupPermissions,
                ]),
            ),
        );
    };

    return (
        <div className="space-y-6">
            {/* Role name */}
            <div className="rounded-xl border bg-background p-5 shadow-sm">
                <label className="mb-2 block text-sm font-medium">
                    Role Name
                </label>

                <input
                    type="text"
                    value={name}
                    onChange={(
                        event,
                    ) =>
                        setName(
                            event
                                .target
                                .value,
                        )
                    }
                    disabled={
                        protectedRole
                    }
                    placeholder="example: hr-manager"
                    className="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20 disabled:opacity-60"
                />

                <p className="mt-2 text-xs text-muted-foreground">
                    Use lowercase words
                    separated by hyphens,
                    for example{" "}
                    <strong>
                        accounts-manager
                    </strong>
                    .
                </p>

                {nameError && (
                    <p className="mt-2 text-sm text-destructive">
                        {nameError}
                    </p>
                )}
            </div>

            {/* Permissions */}
            <div className="rounded-xl border bg-background p-5 shadow-sm">
                <div className="mb-5 flex items-center justify-between">
                    <div>
                        <h2 className="font-semibold">
                            Permissions
                        </h2>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Select what this
                            role is allowed to
                            do.
                        </p>
                    </div>

                    <span className="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                        {
                            permissions.length
                        }{" "}
                        selected
                    </span>
                </div>

                <div className="space-y-5">
                    {Object.entries(
                        permissionGroups,
                    ).map(
                        ([
                            group,
                            groupPermissions,
                        ]) => {
                            const allSelected =
                                groupPermissions.every(
                                    (
                                        permission,
                                    ) =>
                                        permissions.includes(
                                            permission,
                                        ),
                                );

                            return (
                                <div
                                    key={
                                        group
                                    }
                                    className="overflow-hidden rounded-xl border"
                                >
                                    <div className="flex items-center justify-between border-b bg-muted/40 px-4 py-3">
                                        <div className="flex items-center gap-2">
                                            <ShieldCheck className="size-4 text-primary" />

                                            <h3 className="font-semibold capitalize">
                                                {group.replace(
                                                    /-/g,
                                                    " ",
                                                )}
                                            </h3>
                                        </div>

                                        {!protectedRole && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    toggleGroup(
                                                        groupPermissions,
                                                    )
                                                }
                                                className="text-xs font-semibold text-primary hover:underline"
                                            >
                                                {allSelected
                                                    ? "Unselect All"
                                                    : "Select All"}
                                            </button>
                                        )}
                                    </div>

                                    <div className="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                                        {groupPermissions.map(
                                            (
                                                permission,
                                            ) => {
                                                const checked =
                                                    permissions.includes(
                                                        permission,
                                                    );

                                                return (
                                                    <label
                                                        key={
                                                            permission
                                                        }
                                                        className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition ${
                                                            checked
                                                                ? "border-primary/30 bg-primary/5"
                                                                : "hover:bg-muted/40"
                                                        } ${
                                                            protectedRole
                                                                ? "pointer-events-none"
                                                                : ""
                                                        }`}
                                                    >
                                                        <span
                                                            className={`flex size-5 shrink-0 items-center justify-center rounded border ${
                                                                checked
                                                                    ? "border-primary bg-primary text-primary-foreground"
                                                                    : "border-input"
                                                            }`}
                                                        >
                                                            {checked && (
                                                                <Check className="size-3.5" />
                                                            )}
                                                        </span>

                                                        <input
                                                            type="checkbox"
                                                            checked={
                                                                checked
                                                            }
                                                            disabled={
                                                                protectedRole
                                                            }
                                                            onChange={() =>
                                                                togglePermission(
                                                                    permission,
                                                                )
                                                            }
                                                            className="sr-only"
                                                        />

                                                        <span className="text-sm">
                                                            {formatPermission(
                                                                permission,
                                                            )}
                                                        </span>
                                                    </label>
                                                );
                                            },
                                        )}
                                    </div>
                                </div>
                            );
                        },
                    )}
                </div>
            </div>
        </div>
    );
}

function formatPermission(
    permission: string,
) {
    const action =
        permission.split(".")[1] ??
        permission;

    return action
        .replace(
            /-/g,
            " ",
        )
        .replace(
            /\b\w/g,
            (letter) =>
                letter.toUpperCase(),
        );
}