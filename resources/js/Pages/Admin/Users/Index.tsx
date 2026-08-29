import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Pencil,
    Plus,
    Search,
    Trash2,
    UserRound,
} from "lucide-react";

import {
    useEffect,
    useState,
} from "react";

import {
    Button,
} from "@/Components/ui/button";

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from "@/Components/ui/alert-dialog";
import {
    useAuthorization,
} from "@/hooks/useAuthorization";
type User = {
    id: number;

    name: string;
    email: string;

    email_verified_at:
        | string
        | null;

    created_at: string;

    roles: {
        id: number;
        name: string;
    }[];

    profile: {
        profile_photo:
            | string
            | null;

        employee_id:
            | string
            | null;

        designation:
            | string
            | null;

        department:
            | string
            | null;

        phone:
            | string
            | null;
    } | null;
};

type PaginationLink = {
    url: string | null;

    label: string;

    active: boolean;
};

type PaginatedUsers = {
    data: User[];

    links: PaginationLink[];

    current_page: number;

    last_page: number;

    from: number | null;

    to: number | null;

    total: number;
};

type Filters = {
    search?: string;
};

type Props = {
    users: PaginatedUsers;

    filters: Filters;
};

export default function Index({
    users,
    filters,
}: Props) {
    const [
        search,
        setSearch,
    ] = useState(
        filters.search ?? "",
    );

    const {
        can,
    } = useAuthorization();

    useEffect(() => {
        const timeout =
            window.setTimeout(() => {
                router.get(
                    "/admin/users",
                    search
                        ? {
                              search,
                          }
                        : {},
                    {
                        preserveState:
                            true,

                        preserveScroll:
                            true,

                        replace:
                            true,
                    },
                );
            }, 400);

        return () => {
            window.clearTimeout(
                timeout,
            );
        };
    }, [search]);

    return (
        <AdminLayout>
            <Head title="Users" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Users
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Manage users,
                            profiles and role
                            assignments.
                        </p>
                    </div>

                    {can(
                        "users.create",
                    ) && (
                        <Link
                            href="/admin/users/create"
                            className="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-sm transition hover:bg-primary/90"
                        >
                            <Plus className="size-4" />

                            Create User
                        </Link>
                    )}
                </div>

                {/* Toolbar */}
                <div className="flex flex-col gap-4 rounded-xl border bg-background p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div className="relative w-full sm:max-w-md">
                        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />

                        <input
                            type="search"
                            value={
                                search
                            }
                            onChange={(
                                event,
                            ) =>
                                setSearch(
                                    event
                                        .target
                                        .value,
                                )
                            }
                            placeholder="Search by name, email, employee ID, phone..."
                            className="h-10 w-full rounded-lg border border-input bg-background pl-9 pr-3 text-sm outline-none transition focus:border-ring focus:ring-3 focus:ring-ring/20"
                        />
                    </div>

                    <div className="text-sm text-muted-foreground">
                        {users.total}{" "}
                        {users.total === 1
                            ? "user"
                            : "users"}
                    </div>
                </div>

                {/* Table */}
                <div className="overflow-hidden rounded-xl border bg-background shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1150px] text-left text-sm">
                            <thead className="border-b bg-muted/40">
                                <tr>
                                    <th className="px-5 py-3 font-semibold">
                                        User
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Employee
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Email
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Roles
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Verification
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Registered
                                    </th>

                                    <th className="px-5 py-3 text-right font-semibold">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y">
                                {users.data.length >
                                0 ? (
                                    users.data.map(
                                        (
                                            user,
                                        ) => (
                                            <tr
                                                key={
                                                    user.id
                                                }
                                                className="transition hover:bg-muted/30"
                                            >
                                                {/* User */}
                                                <td className="px-5 py-4">
                                                    <div className="flex items-center gap-3">
                                                        {user
                                                            .profile
                                                            ?.profile_photo ? (
                                                            <img
                                                                src={`/storage/${user.profile.profile_photo}`}
                                                                alt={
                                                                    user.name
                                                                }
                                                                className="size-11 shrink-0 rounded-full object-cover"
                                                            />
                                                        ) : (
                                                            <div className="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                                                <UserRound className="size-5" />
                                                            </div>
                                                        )}

                                                        <div className="min-w-0">
                                                            <div className="font-semibold">
                                                                {
                                                                    user.name
                                                                }
                                                            </div>

                                                            {user
                                                                .profile
                                                                ?.designation && (
                                                                <div className="mt-0.5 text-xs text-muted-foreground">
                                                                    {
                                                                        user
                                                                            .profile
                                                                            .designation
                                                                    }
                                                                </div>
                                                            )}

                                                            <div className="mt-0.5 text-xs text-muted-foreground">
                                                                ID:{" "}
                                                                {
                                                                    user.id
                                                                }
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Employee */}
                                                <td className="px-5 py-4">
                                                    <div className="space-y-1">
                                                        <div className="font-medium">
                                                            {user
                                                                .profile
                                                                ?.employee_id ??
                                                                "—"}
                                                        </div>

                                                        {user
                                                            .profile
                                                            ?.department && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {
                                                                    user
                                                                        .profile
                                                                        .department
                                                                }
                                                            </div>
                                                        )}

                                                        {user
                                                            .profile
                                                            ?.phone && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {
                                                                    user
                                                                        .profile
                                                                        .phone
                                                                }
                                                            </div>
                                                        )}
                                                    </div>
                                                </td>

                                                {/* Email */}
                                                <td className="px-5 py-4">
                                                    {
                                                        user.email
                                                    }
                                                </td>

                                                {/* Roles */}
                                                <td className="px-5 py-4">
                                                    <div className="flex max-w-[260px] flex-wrap gap-1.5">
                                                        {user
                                                            .roles
                                                            .length >
                                                        0 ? (
                                                            user.roles.map(
                                                                (
                                                                    role,
                                                                ) => (
                                                                    <span
                                                                        key={
                                                                            role.id
                                                                        }
                                                                        className="rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold capitalize text-primary"
                                                                    >
                                                                        {role.name.replace(
                                                                            /-/g,
                                                                            " ",
                                                                        )}
                                                                    </span>
                                                                ),
                                                            )
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                No
                                                                role
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>

                                                {/* Verification */}
                                                <td className="px-5 py-4">
                                                    {user.email_verified_at ? (
                                                        <span className="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                                            Verified
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                                                            Unverified
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Registered */}
                                                <td className="px-5 py-4 text-muted-foreground">
                                                    {formatDate(
                                                        user.created_at,
                                                    )}
                                                </td>

                                                {/* Actions */}
                                                <td className="px-5 py-4">
                                                    <UserActions
                                                        user={
                                                            user
                                                        }
                                                        canEdit={
                                                            can(
                                                                "users.edit"
                                                            )
                                                        }
                                                        canDelete={
                                                            can(
                                                                "users.delete"
                                                            )
                                                        }
                                                    />
                                                </td>
                                            </tr>
                                        ),
                                    )
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-6 py-14 text-center"
                                        >
                                            <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted">
                                                <UserRound className="size-5 text-muted-foreground" />
                                            </div>

                                            <h3 className="mt-4 font-semibold">
                                                No
                                                users
                                                found
                                            </h3>

                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {search
                                                    ? "Try another search term."
                                                    : "No users have been created yet."}
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Pagination */}
                {users.last_page >
                    1 && (
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div className="text-sm text-muted-foreground">
                            Showing{" "}
                            {users.from ??
                                0}
                            {" - "}
                            {users.to ??
                                0}
                            {" of "}
                            {users.total}
                        </div>

                        <div className="flex flex-wrap gap-1">
                            {users.links.map(
                                (
                                    link,
                                    index,
                                ) =>
                                    link.url ? (
                                        <Link
                                            key={
                                                index
                                            }
                                            href={
                                                link.url
                                            }
                                            preserveScroll
                                            className={`rounded-md border px-3 py-2 text-sm transition ${
                                                link.active
                                                    ? "bg-primary text-primary-foreground"
                                                    : "bg-background hover:bg-muted"
                                            }`}
                                        >
                                            {cleanLabel(
                                                link.label,
                                            )}
                                        </Link>
                                    ) : (
                                        <span
                                            key={
                                                index
                                            }
                                            className="rounded-md border px-3 py-2 text-sm opacity-40"
                                        >
                                            {cleanLabel(
                                                link.label,
                                            )}
                                        </span>
                                    ),
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}

function UserActions({
    user,
    canEdit,
    canDelete,
}: {
    user: User;

    canEdit: boolean;

    canDelete: boolean;
}) {
    const [
        deleteOpen,
        setDeleteOpen,
    ] = useState(false);

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const destroy = () => {
        setDeleting(true);

        router.delete(
            `/admin/users/${user.id}`,
            {
                preserveScroll:
                    true,

                onSuccess: () => {
                    setDeleteOpen(
                        false,
                    );
                },

                onFinish: () => {
                    setDeleting(
                        false,
                    );
                },
            },
        );
    };

    return (
        <div className="flex justify-end gap-2">
            {canEdit && (
                <Link
                    href={`/admin/users/${user.id}/edit`}
                    aria-label={`Edit ${user.name}`}
                    className="inline-flex size-8 items-center justify-center rounded-md border border-input bg-background shadow-sm transition hover:bg-muted"
                >
                    <Pencil className="size-4" />
                </Link>
            )}
            
            {canDelete && (
            <AlertDialogTrigger
                isOpen={
                    deleteOpen
                }
                onOpenChange={
                    setDeleteOpen
                }
            >
                <Button
                    type="button"
                    variant="destructive"
                    size="icon-sm"
                    aria-label={`Delete ${user.name}`}
                >
                    <Trash2 />
                </Button>

                <AlertDialog>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete this
                            user?
                        </AlertDialogTitle>

                        <AlertDialogDescription>
                            This will
                            permanently
                            delete{" "}
                            <strong>
                                {
                                    user.name
                                }
                            </strong>
                            , their profile,
                            photo and role
                            assignments.
                            This action
                            cannot be
                            undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>

                    <AlertDialogFooter>
                        <AlertDialogCancel
                            isDisabled={
                                deleting
                            }
                        >
                            Cancel
                        </AlertDialogCancel>

                        <AlertDialogAction
                            variant="destructive"
                            onPress={
                                destroy
                            }
                            isDisabled={
                                deleting
                            }
                        >
                            {deleting
                                ? "Deleting..."
                                : "Delete User"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialog>
            </AlertDialogTrigger>
            )}
        </div>
    );
}

function formatDate(
    value: string,
) {
    return new Intl.DateTimeFormat(
        "en-GB",
        {
            day: "2-digit",
            month: "short",
            year: "numeric",

            hour: "2-digit",
            minute: "2-digit",
        },
    ).format(
        new Date(value),
    );
}

function cleanLabel(
    value: string,
) {
    return value
        .replace(
            "&laquo;",
            "«",
        )
        .replace(
            "&raquo;",
            "»",
        );
}